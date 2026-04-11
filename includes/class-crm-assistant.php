<?php
/**
 * My_IAPSNJ_CRM_Assistant
 *
 * AI-powered chat interface for FluentCRM management. Supports Anthropic,
 * OpenAI, and Google Gemini as AI providers. Exposes 7 FluentCRM tools that
 * the AI can call to search contacts, read/update records, and manage tags.
 *
 * @package My_IAPSNJ
 */

defined( 'ABSPATH' ) || exit;

class My_IAPSNJ_CRM_Assistant {

    /** @var self|null */
    private static ?self $instance = null;

    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_my_iapsnj_crm_assistant_chat', [ $this, 'ajax_crm_chat' ] );
    }

    // -------------------------------------------------------------------------
    // AJAX entry point
    // -------------------------------------------------------------------------

    public function ajax_crm_chat(): void {
        check_ajax_referer( 'my_iapsnj_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Insufficient permissions.' ], 403 );
        }

        $message = sanitize_textarea_field( wp_unslash( $_POST['message'] ?? '' ) );
        if ( '' === $message ) {
            wp_send_json_error( [ 'message' => 'Empty message.' ], 400 );
        }

        // History is a JSON-encoded array of {role, content} objects.
        $raw_history = wp_unslash( $_POST['history'] ?? '[]' );
        $history     = json_decode( $raw_history, true );
        if ( ! is_array( $history ) ) {
            $history = [];
        }
        // Sanitize each history item.
        $history = array_map( function ( $item ) {
            return [
                'role'    => sanitize_text_field( $item['role'] ?? 'user' ),
                'content' => sanitize_textarea_field( $item['content'] ?? '' ),
            ];
        }, $history );

        $settings = get_option( 'my_iapsnj_settings', [] );
        $provider = $settings['ai_provider'] ?? 'anthropic';

        try {
            [ $reply, $tool_log ] = $this->run_agentic_loop( $provider, $settings, $history, $message );
            wp_send_json_success( [
                'reply'    => $reply,
                'tool_log' => $tool_log,
            ] );
        } catch ( \Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ], 500 );
        }
    }

    // -------------------------------------------------------------------------
    // Agentic loop (provider-agnostic outer loop)
    // -------------------------------------------------------------------------

    /**
     * Runs the message → tool-call → response loop until the AI produces
     * a final text reply.
     *
     * @return array{0: string, 1: array}  [final_reply, tool_log]
     */
    private function run_agentic_loop(
        string $provider,
        array  $settings,
        array  $history,
        string $message
    ): array {
        $tool_log  = [];
        $max_loops = 6;           // Safety ceiling — avoids infinite loops.
        $loop      = 0;

        // Append current user message to history.
        $history[] = [ 'role' => 'user', 'content' => $message ];

        while ( $loop < $max_loops ) {
            $loop++;

            switch ( $provider ) {
                case 'openai':
                    $raw = $this->call_openai( $settings, $history );
                    break;
                case 'gemini':
                    $raw = $this->call_gemini( $settings, $history );
                    break;
                default:
                    $raw = $this->call_anthropic( $settings, $history );
                    break;
            }

            // Did the AI request tool calls?
            $tool_calls = $raw['tool_calls'] ?? [];

            if ( empty( $tool_calls ) ) {
                // Final text response.
                return [ $raw['text'] ?? '', $tool_log ];
            }

            // Execute each tool call and collect results.
            $tool_results = [];
            foreach ( $tool_calls as $tc ) {
                $name  = $tc['name']  ?? '';
                $input = $tc['input'] ?? $tc['arguments'] ?? [];
                if ( is_string( $input ) ) {
                    $input = json_decode( $input, true ) ?? [];
                }

                $result      = $this->dispatch_tool( $name, $input );
                $tool_log[]  = [
                    'tool'   => $name,
                    'input'  => $input,
                    'result' => $result,
                ];

                $tool_results[] = [
                    'tool_use_id' => $tc['id'] ?? $name,
                    'name'        => $name,
                    'content'     => is_string( $result )
                        ? $result
                        : wp_json_encode( $result ),
                ];
            }

            // Feed results back into history for the next loop iteration.
            // The exact format differs per provider; we normalise into a
            // provider-neutral representation and let each call_* method
            // translate it appropriately.
            $history[] = [
                'role'         => 'assistant',
                'tool_calls'   => $tool_calls,
                'tool_results' => $tool_results,
                '_raw_tc'      => true,   // Flag: this entry contains tool calls.
            ];
        }

        return [ 'Maximum tool iterations reached.', $tool_log ];
    }

    // -------------------------------------------------------------------------
    // Tool dispatcher
    // -------------------------------------------------------------------------

    private function dispatch_tool( string $name, array $input ): mixed {
        switch ( $name ) {
            case 'search_contacts':
                return $this->tool_search_contacts( $input );
            case 'get_contact':
                return $this->tool_get_contact( $input );
            case 'update_contact':
                return $this->tool_update_contact( $input );
            case 'add_tag':
                return $this->tool_add_tag( $input );
            case 'remove_tag':
                return $this->tool_remove_tag( $input );
            case 'list_tags':
                return $this->tool_list_tags();
            case 'get_stats':
                return $this->tool_get_stats();
            default:
                return [ 'error' => "Unknown tool: {$name}" ];
        }
    }

    // -------------------------------------------------------------------------
    // FluentCRM tools
    // -------------------------------------------------------------------------

    private function tool_search_contacts( array $input ): array {
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            return [ 'error' => 'FluentCRM is not active.' ];
        }

        $query = sanitize_text_field( $input['query'] ?? '' );
        $limit = min( (int) ( $input['limit'] ?? 10 ), 50 );

        if ( '' === $query ) {
            return [ 'error' => 'query is required.' ];
        }

        $results = \FluentCrm\App\Models\Subscriber::where( function ( $q ) use ( $query ) {
            $q->where( 'email', 'LIKE', '%' . $query . '%' )
              ->orWhere( 'first_name', 'LIKE', '%' . $query . '%' )
              ->orWhere( 'last_name',  'LIKE', '%' . $query . '%' );
        } )->limit( $limit )->get();

        return array_map( [ $this, 'format_subscriber' ], $results->toArray() );
    }

    private function tool_get_contact( array $input ): array {
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            return [ 'error' => 'FluentCRM is not active.' ];
        }

        if ( ! empty( $input['id'] ) ) {
            $sub = \FluentCrm\App\Models\Subscriber::find( (int) $input['id'] );
        } elseif ( ! empty( $input['email'] ) ) {
            $sub = \FluentCrm\App\Models\Subscriber::where( 'email', sanitize_email( $input['email'] ) )->first();
        } else {
            return [ 'error' => 'Provide id or email.' ];
        }

        if ( ! $sub ) {
            return [ 'error' => 'Contact not found.' ];
        }

        $data = $this->format_subscriber( $sub->toArray() );

        // Include custom field values.
        if ( method_exists( $sub, 'custom_fields' ) ) {
            $data['custom_fields'] = $sub->custom_fields();
        }

        // Include tags.
        if ( method_exists( $sub, 'tags' ) ) {
            $tags = $sub->tags()->get();
            $data['tags'] = array_map( fn( $t ) => [ 'id' => $t->id, 'title' => $t->title ], $tags->toArray() );
        }

        return $data;
    }

    private function tool_update_contact( array $input ): array {
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            return [ 'error' => 'FluentCRM is not active.' ];
        }

        $id = (int) ( $input['id'] ?? 0 );
        if ( ! $id ) {
            return [ 'error' => 'id is required.' ];
        }

        $sub = \FluentCrm\App\Models\Subscriber::find( $id );
        if ( ! $sub ) {
            return [ 'error' => 'Contact not found.' ];
        }

        $fields = $input['fields'] ?? [];
        if ( ! is_array( $fields ) || empty( $fields ) ) {
            return [ 'error' => 'fields object is required.' ];
        }

        // Separate standard fields from custom fields.
        $standard_keys = [
            'first_name', 'last_name', 'email', 'phone', 'address_line_1',
            'address_line_2', 'city', 'state', 'postal_code', 'country',
            'company', 'status',
        ];

        $std_data    = [];
        $custom_data = [];

        foreach ( $fields as $key => $value ) {
            $key = sanitize_key( $key );
            if ( in_array( $key, $standard_keys, true ) ) {
                $std_data[ $key ] = sanitize_text_field( $value );
            } else {
                $custom_data[ $key ] = sanitize_text_field( $value );
            }
        }

        if ( ! empty( $std_data ) ) {
            $sub->fill( $std_data );
            $sub->save();
        }

        if ( ! empty( $custom_data ) && method_exists( $sub, 'syncCustomFieldValues' ) ) {
            $sub->syncCustomFieldValues( $custom_data );
        }

        return [ 'updated' => true, 'id' => $id ];
    }

    private function tool_add_tag( array $input ): array {
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            return [ 'error' => 'FluentCRM is not active.' ];
        }

        $subscriber_id = (int) ( $input['subscriber_id'] ?? 0 );
        $tag_name      = sanitize_text_field( $input['tag'] ?? '' );

        if ( ! $subscriber_id || ! $tag_name ) {
            return [ 'error' => 'subscriber_id and tag are required.' ];
        }

        $sub = \FluentCrm\App\Models\Subscriber::find( $subscriber_id );
        if ( ! $sub ) {
            return [ 'error' => 'Contact not found.' ];
        }

        $tag_id = $this->resolve_tag_id( $tag_name );
        if ( ! $tag_id ) {
            return [ 'error' => "Tag '{$tag_name}' not found." ];
        }

        $sub->attachTags( [ $tag_id ] );
        return [ 'added' => true, 'tag_id' => $tag_id ];
    }

    private function tool_remove_tag( array $input ): array {
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            return [ 'error' => 'FluentCRM is not active.' ];
        }

        $subscriber_id = (int) ( $input['subscriber_id'] ?? 0 );
        $tag_name      = sanitize_text_field( $input['tag'] ?? '' );

        if ( ! $subscriber_id || ! $tag_name ) {
            return [ 'error' => 'subscriber_id and tag are required.' ];
        }

        $sub = \FluentCrm\App\Models\Subscriber::find( $subscriber_id );
        if ( ! $sub ) {
            return [ 'error' => 'Contact not found.' ];
        }

        $tag_id = $this->resolve_tag_id( $tag_name );
        if ( ! $tag_id ) {
            return [ 'error' => "Tag '{$tag_name}' not found." ];
        }

        $sub->detachTags( [ $tag_id ] );
        return [ 'removed' => true, 'tag_id' => $tag_id ];
    }

    private function tool_list_tags(): array {
        if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
            return [ 'error' => 'FluentCRM is not active.' ];
        }

        $tags = \FluentCrm\App\Models\Tag::orderBy( 'title' )->get();
        return array_map(
            fn( $t ) => [ 'id' => $t->id, 'title' => $t->title, 'slug' => $t->slug ],
            $tags->toArray()
        );
    }

    private function tool_get_stats(): array {
        if ( ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            return [ 'error' => 'FluentCRM is not active.' ];
        }

        $total      = \FluentCrm\App\Models\Subscriber::count();
        $subscribed = \FluentCrm\App\Models\Subscriber::where( 'status', 'subscribed' )->count();
        $pending    = \FluentCrm\App\Models\Subscriber::where( 'status', 'pending' )->count();
        $bounced    = \FluentCrm\App\Models\Subscriber::where( 'status', 'bounced' )->count();
        $unsubscribed = \FluentCrm\App\Models\Subscriber::where( 'status', 'unsubscribed' )->count();

        $tag_count = class_exists( '\FluentCrm\App\Models\Tag' )
            ? \FluentCrm\App\Models\Tag::count()
            : 'N/A';

        return [
            'total_contacts'   => $total,
            'subscribed'       => $subscribed,
            'pending'          => $pending,
            'unsubscribed'     => $unsubscribed,
            'bounced'          => $bounced,
            'total_tags'       => $tag_count,
        ];
    }

    // -------------------------------------------------------------------------
    // Provider: Anthropic
    // -------------------------------------------------------------------------

    private function call_anthropic( array $settings, array $history ): array {
        $api_key = $settings['anthropic_api_key'] ?? '';
        if ( ! $api_key ) {
            throw new \RuntimeException( 'Anthropic API key is not configured.' );
        }

        $messages = $this->history_to_anthropic( $history );

        $body = [
            'model'      => 'claude-sonnet-4-6',
            'max_tokens' => 4096,
            'system'     => $this->get_system_prompt(),
            'tools'      => $this->get_tool_definitions_anthropic(),
            'messages'   => $messages,
        ];

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        $this->assert_http_ok( $response, 'Anthropic' );

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $content = $data['content'] ?? [];

        $text       = '';
        $tool_calls = [];

        foreach ( $content as $block ) {
            if ( ( $block['type'] ?? '' ) === 'text' ) {
                $text .= $block['text'];
            } elseif ( ( $block['type'] ?? '' ) === 'tool_use' ) {
                $tool_calls[] = [
                    'id'    => $block['id'],
                    'name'  => $block['name'],
                    'input' => $block['input'] ?? [],
                ];
            }
        }

        return [ 'text' => $text, 'tool_calls' => $tool_calls ];
    }

    /**
     * Convert the neutral history array to Anthropic messages format.
     */
    private function history_to_anthropic( array $history ): array {
        $messages = [];

        foreach ( $history as $item ) {
            if ( ! empty( $item['_raw_tc'] ) ) {
                // Assistant turn with tool calls.
                $content = [];
                foreach ( $item['tool_calls'] as $tc ) {
                    $content[] = [
                        'type'  => 'tool_use',
                        'id'    => $tc['id'] ?? $tc['name'],
                        'name'  => $tc['name'],
                        'input' => $tc['input'] ?? [],
                    ];
                }
                $messages[] = [ 'role' => 'assistant', 'content' => $content ];

                // Tool results go into a "user" turn.
                $result_blocks = [];
                foreach ( $item['tool_results'] as $tr ) {
                    $result_blocks[] = [
                        'type'        => 'tool_result',
                        'tool_use_id' => $tr['tool_use_id'],
                        'content'     => $tr['content'],
                    ];
                }
                $messages[] = [ 'role' => 'user', 'content' => $result_blocks ];
            } else {
                $messages[] = [
                    'role'    => $item['role'],
                    'content' => $item['content'],
                ];
            }
        }

        return $messages;
    }

    private function get_tool_definitions_anthropic(): array {
        return [
            [
                'name'        => 'search_contacts',
                'description' => 'Search FluentCRM contacts by name or email.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'query' => [ 'type' => 'string', 'description' => 'Search term (name or email).' ],
                        'limit' => [ 'type' => 'integer', 'description' => 'Max results (default 10, max 50).', 'default' => 10 ],
                    ],
                    'required' => [ 'query' ],
                ],
            ],
            [
                'name'        => 'get_contact',
                'description' => 'Get a FluentCRM contact by ID or email, including tags and custom fields.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'    => [ 'type' => 'integer', 'description' => 'FluentCRM subscriber ID.' ],
                        'email' => [ 'type' => 'string',  'description' => 'Contact email address.' ],
                    ],
                ],
            ],
            [
                'name'        => 'update_contact',
                'description' => 'Update one or more fields on a FluentCRM contact.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'id'     => [ 'type' => 'integer', 'description' => 'FluentCRM subscriber ID.' ],
                        'fields' => [
                            'type'        => 'object',
                            'description' => 'Key-value pairs of fields to update (standard or custom).',
                        ],
                    ],
                    'required' => [ 'id', 'fields' ],
                ],
            ],
            [
                'name'        => 'add_tag',
                'description' => 'Add a tag to a FluentCRM contact by tag name.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'subscriber_id' => [ 'type' => 'integer', 'description' => 'FluentCRM subscriber ID.' ],
                        'tag'           => [ 'type' => 'string',  'description' => 'Tag title (case-insensitive).' ],
                    ],
                    'required' => [ 'subscriber_id', 'tag' ],
                ],
            ],
            [
                'name'        => 'remove_tag',
                'description' => 'Remove a tag from a FluentCRM contact by tag name.',
                'input_schema' => [
                    'type'       => 'object',
                    'properties' => [
                        'subscriber_id' => [ 'type' => 'integer', 'description' => 'FluentCRM subscriber ID.' ],
                        'tag'           => [ 'type' => 'string',  'description' => 'Tag title (case-insensitive).' ],
                    ],
                    'required' => [ 'subscriber_id', 'tag' ],
                ],
            ],
            [
                'name'        => 'list_tags',
                'description' => 'List all available FluentCRM tags.',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
            ],
            [
                'name'        => 'get_stats',
                'description' => 'Get overall FluentCRM statistics (contact counts by status, total tags).',
                'input_schema' => [ 'type' => 'object', 'properties' => [] ],
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Provider: OpenAI
    // -------------------------------------------------------------------------

    private function call_openai( array $settings, array $history ): array {
        $api_key = $settings['openai_api_key'] ?? '';
        if ( ! $api_key ) {
            throw new \RuntimeException( 'OpenAI API key is not configured.' );
        }

        $messages = $this->history_to_openai( $history );

        $body = [
            'model'    => 'gpt-4o',
            'messages' => $messages,
            'tools'    => $this->get_tool_definitions_openai(),
        ];

        $response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        $this->assert_http_ok( $response, 'OpenAI' );

        $data    = json_decode( wp_remote_retrieve_body( $response ), true );
        $message = $data['choices'][0]['message'] ?? [];

        $text       = $message['content'] ?? '';
        $tool_calls = [];

        foreach ( $message['tool_calls'] ?? [] as $tc ) {
            $tool_calls[] = [
                'id'    => $tc['id'],
                'name'  => $tc['function']['name'],
                'input' => $tc['function']['arguments'],  // JSON string — decoded in dispatch.
            ];
        }

        return [ 'text' => $text, 'tool_calls' => $tool_calls ];
    }

    private function history_to_openai( array $history ): array {
        $messages = [
            [ 'role' => 'system', 'content' => $this->get_system_prompt() ],
        ];

        foreach ( $history as $item ) {
            if ( ! empty( $item['_raw_tc'] ) ) {
                // Assistant turn with tool calls.
                $oai_tool_calls = [];
                foreach ( $item['tool_calls'] as $tc ) {
                    $oai_tool_calls[] = [
                        'id'       => $tc['id'] ?? $tc['name'],
                        'type'     => 'function',
                        'function' => [
                            'name'      => $tc['name'],
                            'arguments' => is_array( $tc['input'] )
                                ? wp_json_encode( $tc['input'] )
                                : (string) $tc['input'],
                        ],
                    ];
                }
                $messages[] = [ 'role' => 'assistant', 'content' => null, 'tool_calls' => $oai_tool_calls ];

                // Individual tool result messages.
                foreach ( $item['tool_results'] as $tr ) {
                    $messages[] = [
                        'role'         => 'tool',
                        'tool_call_id' => $tr['tool_use_id'],
                        'content'      => $tr['content'],
                    ];
                }
            } else {
                $messages[] = [
                    'role'    => $item['role'],
                    'content' => $item['content'],
                ];
            }
        }

        return $messages;
    }

    private function get_tool_definitions_openai(): array {
        $anthropic_defs = $this->get_tool_definitions_anthropic();
        $oai_tools      = [];

        foreach ( $anthropic_defs as $def ) {
            $oai_tools[] = [
                'type'     => 'function',
                'function' => [
                    'name'        => $def['name'],
                    'description' => $def['description'],
                    'parameters'  => $def['input_schema'],
                ],
            ];
        }

        return $oai_tools;
    }

    // -------------------------------------------------------------------------
    // Provider: Google Gemini
    // -------------------------------------------------------------------------

    private function call_gemini( array $settings, array $history ): array {
        $api_key = $settings['gemini_api_key'] ?? '';
        if ( ! $api_key ) {
            throw new \RuntimeException( 'Gemini API key is not configured.' );
        }

        $contents = $this->history_to_gemini( $history );

        $body = [
            'system_instruction' => [
                'parts' => [ [ 'text' => $this->get_system_prompt() ] ],
            ],
            'contents' => $contents,
            'tools'    => [
                [ 'function_declarations' => $this->get_tool_definitions_gemini() ],
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-pro:generateContent?key='
            . rawurlencode( $api_key );

        $response = wp_remote_post( $url, [
            'headers' => [ 'Content-Type' => 'application/json' ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 60,
        ] );

        $this->assert_http_ok( $response, 'Gemini' );

        $data  = json_decode( wp_remote_retrieve_body( $response ), true );
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        $role  = $data['candidates'][0]['content']['role'] ?? 'model';

        $text       = '';
        $tool_calls = [];

        foreach ( $parts as $part ) {
            if ( isset( $part['text'] ) ) {
                $text .= $part['text'];
            } elseif ( isset( $part['functionCall'] ) ) {
                $fc = $part['functionCall'];
                $tool_calls[] = [
                    'id'    => $fc['name'] . '_' . uniqid(),
                    'name'  => $fc['name'],
                    'input' => $fc['args'] ?? [],
                ];
            }
        }

        return [ 'text' => $text, 'tool_calls' => $tool_calls ];
    }

    private function history_to_gemini( array $history ): array {
        $contents = [];

        foreach ( $history as $item ) {
            if ( ! empty( $item['_raw_tc'] ) ) {
                // Model turn with function calls.
                $fc_parts = [];
                foreach ( $item['tool_calls'] as $tc ) {
                    $fc_parts[] = [
                        'functionCall' => [
                            'name' => $tc['name'],
                            'args' => is_array( $tc['input'] )
                                ? $tc['input']
                                : (array) json_decode( $tc['input'], true ),
                        ],
                    ];
                }
                $contents[] = [ 'role' => 'model', 'parts' => $fc_parts ];

                // Function responses (user role in Gemini).
                $resp_parts = [];
                foreach ( $item['tool_results'] as $tr ) {
                    $result_data = json_decode( $tr['content'], true );
                    if ( ! is_array( $result_data ) ) {
                        $result_data = [ 'result' => $tr['content'] ];
                    }
                    $resp_parts[] = [
                        'functionResponse' => [
                            'name'     => $tr['name'],
                            'response' => $result_data,
                        ],
                    ];
                }
                $contents[] = [ 'role' => 'user', 'parts' => $resp_parts ];
            } else {
                $gemini_role = ( $item['role'] === 'assistant' ) ? 'model' : 'user';
                $contents[]  = [
                    'role'  => $gemini_role,
                    'parts' => [ [ 'text' => $item['content'] ] ],
                ];
            }
        }

        return $contents;
    }

    private function get_tool_definitions_gemini(): array {
        $anthropic_defs = $this->get_tool_definitions_anthropic();
        $gemini_tools   = [];

        foreach ( $anthropic_defs as $def ) {
            // Gemini uses 'parameters' (same JSON Schema object as input_schema).
            $gemini_tools[] = [
                'name'        => $def['name'],
                'description' => $def['description'],
                'parameters'  => $def['input_schema'],
            ];
        }

        return $gemini_tools;
    }

    // -------------------------------------------------------------------------
    // System prompt
    // -------------------------------------------------------------------------

    private function get_system_prompt(): string {
        return <<<'PROMPT'
You are the IAPSNJ CRM Assistant — an AI helper embedded in the WordPress admin
of the Italian American Police Society of New Jersey (IAPSNJ) website.

You help administrators manage FluentCRM contacts and member records.  You have
access to the following tools:

  • search_contacts — find members by name or email
  • get_contact     — retrieve full contact details including custom fields and tags
  • update_contact  — update standard or custom fields on a contact
  • add_tag         — attach a tag to a contact
  • remove_tag      — detach a tag from a contact
  • list_tags       — see all available FluentCRM tags
  • get_stats       — get subscriber count totals

Guidelines:
- Always confirm destructive actions (updates) with the user before proceeding
  unless the user has explicitly asked you to make the change.
- When showing contact data, format it clearly — name, email, member number,
  status, expiration date, tags.
- Keep responses concise and professional.
- If a tool returns an error, explain it clearly and suggest next steps.
- Be aware of IAPSNJ-specific fields: member_number, member_status,
  expiration_date, department, rank_level, union_affiliation, etc.
PROMPT;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function format_subscriber( array $sub ): array {
        return [
            'id'         => $sub['id']         ?? null,
            'email'      => $sub['email']       ?? '',
            'first_name' => $sub['first_name']  ?? '',
            'last_name'  => $sub['last_name']   ?? '',
            'status'     => $sub['status']      ?? '',
            'user_id'    => $sub['user_id']     ?? null,
            'phone'      => $sub['phone']       ?? '',
            'company'    => $sub['company']     ?? '',
            'created_at' => $sub['created_at']  ?? '',
        ];
    }

    private function resolve_tag_id( string $name ): ?int {
        if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ) {
            return null;
        }

        $tag = \FluentCrm\App\Models\Tag::where( 'title', $name )->first();
        if ( ! $tag ) {
            // Case-insensitive fallback.
            $tag = \FluentCrm\App\Models\Tag::whereRaw( 'LOWER(title) = ?', [ strtolower( $name ) ] )->first();
        }

        return $tag ? (int) $tag->id : null;
    }

    /**
     * @param array|\WP_Error $response
     */
    private function assert_http_ok( $response, string $provider ): void {
        if ( is_wp_error( $response ) ) {
            throw new \RuntimeException(
                $provider . ' request failed: ' . $response->get_error_message()
            );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            $msg  = json_decode( $body, true );
            $detail = $msg['error']['message'] ?? $msg['error']['status'] ?? substr( $body, 0, 200 );
            throw new \RuntimeException(
                $provider . " API error (HTTP {$code}): " . $detail
            );
        }
    }
}
