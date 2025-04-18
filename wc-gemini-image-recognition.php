<?php
/**
 * Plugin Name: WooCommerce Image Recognition with Gemini
 * Description: Adds a floating chat bubble that uses Gemini API to recognize products from images and find them in your WooCommerce store.
 * Version: 1.0
 * Author: walid aitbarghous
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class WC_Gemini_Image_Recognition {
    private $gemini_api_key;
    private $max_results;
    private $whatsapp_number;
    private $enable_notifications;

    public function __construct() {
        // Initialize settings
        $this->gemini_api_key = get_option('wc_gemini_api_key', '');
        $this->max_results = get_option('wc_gemini_max_results', 5);
        $this->whatsapp_number = get_option('wc_gemini_whatsapp_number', '');
        $this->enable_notifications = get_option('wc_gemini_enable_notifications', false);

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Add frontend components
        add_action('wp_footer', array($this, 'add_floating_button'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add AJAX handlers
        add_action('wp_ajax_process_image', array($this, 'process_image'));
        add_action('wp_ajax_nopriv_process_image', array($this, 'process_image'));
        // NEW AJAX handler for multi-add
        add_action('wp_ajax_wc_gemini_add_multiple_to_cart', array($this, 'ajax_add_multiple_to_cart'));
        // Note: No _nopriv_ for adding to cart unless you specifically want logged-out users to do this
    }

    // Register plugin settings
    public function register_settings() {
        register_setting('wc_gemini_settings', 'wc_gemini_api_key');
        register_setting('wc_gemini_settings', 'wc_gemini_max_results', array(
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 5,
        ));
        register_setting('wc_gemini_settings', 'wc_gemini_whatsapp_number', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_whatsapp_number'),
            'default' => '',
        ));
        register_setting('wc_gemini_settings', 'wc_gemini_enable_notifications', array(
            'type' => 'boolean',
            'sanitize_callback' => 'rest_sanitize_boolean',
            'default' => false,
        ));
    }

    // Sanitize WhatsApp number
    public function sanitize_whatsapp_number($input) {
        // Remove all non-numeric characters
        $number = preg_replace('/[^0-9]/', '', $input);

        // Check if number is valid (at least 10 digits)
        if (strlen($number) < 10) {
            add_settings_error(
                'wc_gemini_whatsapp_number',
                'invalid_whatsapp',
                'Please enter a valid WhatsApp number with country code (e.g., +1234567890)',
                'error'
            );
            // Return the old value if validation fails
            return get_option('wc_gemini_whatsapp_number', '');
        }

        return $number;
    }

    // Add admin menu page
    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Gemini Image Recognition',
            'Gemini Image Recognition',
            'manage_options',
            'wc-gemini-settings',
            array($this, 'settings_page')
        );
    }

    // Settings page content
    public function settings_page() {
        // Get WhatsApp settings
        $whatsapp_number = get_option('wc_gemini_whatsapp_number', '');
        $enable_notifications = get_option('wc_gemini_enable_notifications', false);
        ?>
        <div class="wrap">
            <h1>Gemini Image Recognition Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wc_gemini_settings'); ?>
                <h2>API Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Gemini API Key</th>
                        <td>
                            <input type="text" name="wc_gemini_api_key" value="<?php echo esc_attr($this->gemini_api_key); ?>" class="regular-text" />
                            <p class="description">Enter your Gemini API key. <a href="https://ai.google.dev/" target="_blank">Get a key here</a>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Maximum Results</th>
                        <td>
                            <input type="number" name="wc_gemini_max_results" value="<?php echo esc_attr($this->max_results); ?>" class="small-text" min="1" max="10" />
                            <p class="description">Maximum number of matching products to display (1-10).</p>
                        </td>
                    </tr>
                </table>

                <h2>WhatsApp Notifications</h2>
                <p class="description">Get notified when customers search for products that aren't found in your store.</p>
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Notifications</th>
                        <td>
                            <label for="wc_gemini_enable_notifications">
                                <input type="checkbox" name="wc_gemini_enable_notifications" id="wc_gemini_enable_notifications" value="1" <?php echo $enable_notifications ? 'checked="checked"' : ''; ?> />
                                Enable WhatsApp notifications for missing products
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WhatsApp Number</th>
                        <td>
                            <input type="text" name="wc_gemini_whatsapp_number" value="<?php echo htmlspecialchars($whatsapp_number, ENT_QUOTES, 'UTF-8'); ?>" class="regular-text" placeholder="+1234567890" />
                            <p class="description">Enter your WhatsApp number with country code (e.g., +1234567890). This is where notifications will be sent.</p>
                        </td>
                    </tr>
                </table>
                <p class="submit"><input type="submit" name="submit" id="submit" class="button button-primary" value="Save Changes"></p>
            </form>
        </div>
        <?php
    }

    // Add floating button to frontend
    public function add_floating_button() {
        ?>
        <div id="wc-gemini-chat-bubble" class="wc-gemini-chat-bubble">
            <span class="wc-gemini-icon">📷</span>
            <div class="wc-gemini-notification-bubble">
                <span>Need help?</span>
            </div>
        </div>

        <div id="wc-gemini-modal" class="wc-gemini-modal">
            <div class="wc-gemini-modal-content">
                <span class="wc-gemini-close">&times;</span>

                <div class="wc-gemini-steps">
                    <div id="wc-gemini-step-1" class="wc-gemini-step active">
                        <h3>Scan Your Products</h3>
                        <p>Upload or take a photo containing one or more products</p>
                        <div class="wc-gemini-image-upload">
                            <input type="file" id="wc-gemini-image-input" accept="image/*" capture>
                            <label for="wc-gemini-image-input">Choose Image or Take Photo</label>
                        </div>
                        <div id="wc-gemini-image-preview" class="wc-gemini-image-preview"></div>
                        <button id="wc-gemini-analyze-btn" class="wc-gemini-button" disabled>Analyze Image</button>
                    </div>

                    <div id="wc-gemini-step-2" class="wc-gemini-step">
                        <div id="wc-gemini-loading" class="wc-gemini-loading">
                            <div class="wc-gemini-spinner"></div>
                            <p>Analyzing image for products...</p>
                        </div>
                        <div id="wc-gemini-results" class="wc-gemini-results">
                            <!-- Results will be loaded here cart-style -->
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    // Enqueue necessary scripts and styles
    public function enqueue_scripts() {
        wp_enqueue_style('wc-gemini-styles', plugin_dir_url(__FILE__) . 'assets/css/style.css', array(), '1.0.1');
        wp_enqueue_script('wc-gemini-script', plugin_dir_url(__FILE__) . 'assets/js/script.js', array('jquery'), '1.0.1', true);

        // Get WooCommerce currency symbol
        $currency_symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '$'; // Default to $ if WC function fails

        wp_localize_script('wc-gemini-script', 'wcGeminiParams', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_gemini_nonce'),
            'placeholder_image_url' => wc_placeholder_img_src('thumbnail'),
            'currency_symbol' => $currency_symbol, // Pass currency symbol to JS
            'store_whatsapp_number' => $this->whatsapp_number // Pass the store's WhatsApp number to JS
        ));
    }

    // Process image through Gemini API
    public function process_image() {
        error_log("WC Gemini: Starting image processing..."); // Log start
        check_ajax_referer('wc_gemini_nonce', 'nonce');

        // Validate API key
        if (empty($this->gemini_api_key)) {
            wp_send_json_error(array('message' => 'Gemini API key is not configured in WooCommerce settings.'));
            return;
        }

        // Check if image was uploaded correctly
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'No image provided or upload error.';
            if (isset($_FILES['image']['error'])) {
                // Provide more specific upload errors if possible
                switch ($_FILES['image']['error']) {
                    case UPLOAD_ERR_INI_SIZE:
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_message = 'Image file size is too large.';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_message = 'Image was only partially uploaded.';
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error_message = 'No image file was uploaded.';
                        break;
                    default:
                        $error_message = 'Unknown image upload error.';
                        break;
                }
            }
            wp_send_json_error(array('message' => $error_message));
            return;
        }

        $image_path = $_FILES['image']['tmp_name'];
        $image_mime_type = mime_content_type($image_path);

        // Validate image type (allow common types)
        $allowed_mime_types = array('image/jpeg', 'image/png', 'image/gif', 'image/webp');
        if (!in_array($image_mime_type, $allowed_mime_types)) {
            wp_send_json_error(array('message' => 'Invalid image file type. Please upload a JPEG, PNG, GIF, or WebP image.'));
            return;
        }

        // Get image data
        $image_data = file_get_contents($image_path);
        if ($image_data === false) {
             wp_send_json_error(array('message' => 'Could not read image file.'));
             return;
        }
        $base64_image = base64_encode($image_data);

        // Prepare Gemini API request
        $response = $this->send_to_gemini_api($base64_image, $image_mime_type);

        // Check for WP_Error during the request itself (e.g., network issue)
        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => 'Error contacting Gemini API: ' . $response->get_error_message()));
            return;
        }

        // Check HTTP response code
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
             $error_data = json_decode($body, true);
             $api_error_message = isset($error_data['error']['message']) ? $error_data['error']['message'] : 'Received status code ' . $response_code;
             wp_send_json_error(array('message' => 'Gemini API Error: ' . $api_error_message));
             return;
        }

        // Decode the successful response body
        $data = json_decode($body, true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($data)) {
            wp_send_json_error(array('message' => 'Error decoding response from Gemini API. Response: ' . substr($body, 0, 1000))); // Log part of the raw body for debugging
            return;
        }

        // Extract product information (expects an array of products)
        $extracted_data = $this->extract_product_info($data);

        // ---> CAPTURE RAW TEXT FOR DEBUGGING <---
        $raw_gemini_text_output = "Could not extract raw text from API response."; // Default
        if (isset($extracted_data['raw_text'])) {
            $raw_gemini_text_output = $extracted_data['raw_text'];
        } elseif(isset($data['candidates'][0]['content']['parts'][0]['text'])) {
             $raw_gemini_text_output = $data['candidates'][0]['content']['parts'][0]['text'];
        }
        // ---> END CAPTURE <---

        if (isset($extracted_data['error'])) {
             error_log("WC Gemini Error: Processing API Response - " . $extracted_data['error']);
             // Send raw output even on error if available
             wp_send_json_error(array(
                 'message' => 'Error processing API response: ' . $extracted_data['error'],
                 'gemini_raw_output' => $raw_gemini_text_output
             ));
             return;
        }
        // Ensure products key exists, even if empty
        if (!isset($extracted_data['products']) || !is_array($extracted_data['products'])) {
             error_log("WC Gemini Error: API response JSON does not contain the expected 'products' array.");
              wp_send_json_error(array(
                  'message' => 'Invalid API response structure (missing products array).',
                  'gemini_raw_output' => $raw_gemini_text_output
              ));
             return;
        }

        $all_matching_products = array();
        $found_product_ids = array();
        $overall_search_type = 'none'; // Default

        // --- Conditional Search Logic ---
        $single_word_text = null;
        if (empty($extracted_data['products']) && !empty($extracted_data['extracted_text'])) {
            $trimmed_text = trim($extracted_data['extracted_text']);
            // Heuristic: Check if it's roughly a single word (no spaces)
            if (strpos($trimmed_text, ' ') === false && strlen($trimmed_text) > 1) {
                $single_word_text = $trimmed_text;
                error_log("WC Gemini Info: No products identified, but single word text found: [" . $single_word_text . "]. Performing text-based search.");
            }
        }

        if ($single_word_text !== null) {
            // --- Scenario A: Search using only the single extracted word ---
            $args_text_search = array(
                'post_type' => 'product',
                'post_status' => 'publish',
                's' => sanitize_text_field($single_word_text),
                'posts_per_page' => absint($this->max_results), // Use max results setting
                'orderby' => 'relevance',
            );
            error_log("WC Gemini Debug: Performing TEXT search with WP_Query args: " . print_r($args_text_search, true));
            $query_text = new WP_Query($args_text_search);

            if ($query_text->have_posts()) {
                 error_log("WC Gemini Debug: TEXT search found " . $query_text->found_posts . " posts for query [$single_word_text].");
                 while ($query_text->have_posts()) {
                     $query_text->the_post();
                     $product_id = get_the_ID();
                     if (!in_array($product_id, $found_product_ids)) {
                         $product = wc_get_product($product_id);
                         if (!$product) continue;
                         $image_id = $product->get_image_id();
                         $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                         $all_matching_products[] = array(
                             'id' => $product_id,
                             'name' => $product->get_name(),
                             'price' => $product->get_price_html() ? $product->get_price_html() : 'Price not available',
                             'raw_price' => $product->get_price(),
                             'image' => $image_url,
                             'url' => $product->get_permalink(),
                             'add_to_cart_url' => $product->is_purchasable() && $product->is_in_stock() ? $product->add_to_cart_url() : null,
                             'search_type_origin' => 'text_search' // Mark how this was found
                         );
                         $found_product_ids[] = $product_id;
                     }
                 }
                 wp_reset_postdata();
                 $overall_search_type = 'text_search'; // Set overall type
            } else {
                 error_log("WC Gemini Debug: TEXT search found 0 posts for query [$single_word_text].");
                 $overall_search_type = 'none';
            }

        } else if (!empty($extracted_data['products'])) {
            // --- Scenario B: Loop through identified products and search using find_matching_products ---
             error_log("WC Gemini Info: Products identified by Gemini. Searching for each item. Count: " . count($extracted_data['products']));
             $search_types = []; // Store if matches were direct or broad

            foreach ($extracted_data['products'] as $index => $product_item_info) {
                 error_log("WC Gemini: Searching for identified item #$index: " . print_r($product_item_info, true));
                 // Search for products, getting back results and the type of search performed
                 $search_result = $this->find_matching_products($product_item_info);
                 $matches = $search_result['products'];
                 $search_type = $search_result['type']; // 'direct', 'broad', or 'none'
                 $search_types[$index] = $search_type; // Store search type for this item

                 error_log("WC Gemini: Found " . count($matches) . " potential matches for item #$index (Search type: $search_type).");

                 // Add unique products to the main list
                 foreach ($matches as $store_product) {
                     if (!in_array($store_product['id'], $found_product_ids)) {
                         // Add the search type to the product data for JS
                         $store_product['search_type_origin'] = $search_type; // Store how this *specific* product was found
                         $all_matching_products[] = $store_product;
                         $found_product_ids[] = $store_product['id'];
                     }
                 }
            }
            // Determine overall result type for frontend message (based on the loop results)
            if (!empty($all_matching_products)) {
                $overall_search_type = 'broad'; // Default to broad if matches exist
                foreach($all_matching_products as $prod) {
                    if (isset($prod['search_type_origin']) && $prod['search_type_origin'] === 'direct') {
                        $overall_search_type = 'direct';
                        break;
                    }
                }
            } else {
                $overall_search_type = 'none';
            }
        } else {
             error_log("WC Gemini Info: No products identified and no usable single-word text found.");
             $overall_search_type = 'none';
        }

        // --- Final Processing & Response ---

        // Limit the final number of results
        if (count($all_matching_products) > absint($this->max_results)) {
             $all_matching_products = array_slice($all_matching_products, 0, absint($this->max_results));
        }

        error_log("WC Gemini Info: Final aggregated matching products being sent (Overall Type: $overall_search_type): " . print_r($all_matching_products, true));

        // Send WhatsApp notification if no products were found but items were identified
        if ($this->enable_notifications && !empty($this->whatsapp_number)) {
            if (empty($all_matching_products) && !empty($extracted_data['products'])) {
                error_log("WC Gemini: No matching products found but items were identified. Sending WhatsApp notification.");
                $this->send_whatsapp_notification($extracted_data['products']);
            } elseif (empty($all_matching_products) && !empty($extracted_data['extracted_text'])) {
                // If no products found but text was extracted, send notification with the text
                error_log("WC Gemini: No matching products found but text was extracted. Sending WhatsApp notification.");
                $this->send_whatsapp_notification([], $extracted_data['extracted_text']);
            }
        } else if (empty($all_matching_products)) {
            error_log("WC Gemini: No matching products found but WhatsApp notifications are disabled or number not set.");
        }

        wp_send_json_success(array(
            'identified_items' => $extracted_data['products'], // Still send identified items even if search was text-based
            'matching_products' => $all_matching_products,
            'overall_search_type' => $overall_search_type,
            'gemini_raw_output' => $raw_gemini_text_output,
            'extracted_text' => isset($extracted_data['extracted_text']) ? $extracted_data['extracted_text'] : ''
        ));
    }

    // Send image to Gemini API
    private function send_to_gemini_api($base64_image, $mime_type) {
        // Use the Gemini 2.0 Flash model for better image recognition
        $api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash-exp:generateContent';
        $api_url .= '?key=' . urlencode($this->gemini_api_key);

        // Enhanced prompt for better text extraction and product recognition
        $prompt = <<<PROMPT
You are a specialized product recognition and text extraction system. Analyze the image with extreme precision.

1. EXTRACT ALL TEXT: First, extract ALL visible text in the image, including product labels, packaging text, signs, handwriting, etc. Be extremely thorough.

2. IDENTIFY PRODUCTS: Identify ALL distinct products visible in the image. For each product, extract as much detail as possible.

Respond ONLY with a valid JSON object containing TWO top-level keys: "products" and "extracted_text".
- The value of "products" MUST be an array of JSON objects, where each object represents ONE distinct product found. If no products are found, use an empty array [].
- The value of "extracted_text" MUST be a string containing ALL text identified in the image, or null if none was found.

Each product object in the "products" array should have the following keys:
- "brand": (string) The brand name, or null if not identifiable.
- "product_name": (string) The specific product name, or null if not identifiable.
- "type": (string) The general type or category (e.g., "Shampoo", "Coffee Mug", "Pen"), or null.
- "size": (string) Size, volume, or quantity (e.g., "500ml", "Large", "12-pack"), or null.
- "visible_text": (string) ALL literal text extracted SPECIFICALLY from the product/packaging, or null.
- "keywords": (array of strings) 5-8 relevant keywords for searching this specific product.
- "description": (string) A detailed description of this specific product.
- "color": (string) The main color(s) of the product, or null if not applicable.
- "material": (string) The main material of the product if visible, or null if not applicable.

Example JSON output for an image containing a pen, a notebook, and text "Buy Milk":
{
  "products": [
    {
      "brand": "PenBrand",
      "product_name": "Gel Pen Fine Point",
      "type": "Pen",
      "size": null,
      "visible_text": "0.5mm Black Ink PenBrand Ultra Smooth",
      "keywords": ["gel pen", "fine point", "black ink", "office supply", "writing", "stationery", "smooth", "0.5mm"],
      "description": "A black gel pen with a 0.5mm fine point tip featuring ultra smooth writing technology.",
      "color": "black",
      "material": "plastic"
    },
    {
      "brand": "NotesCo",
      "product_name": "Spiral Notebook College Ruled",
      "type": "Notebook",
      "size": "8.5x11 inch",
      "visible_text": "100 Pages NotesCo Premium College Ruled",
      "keywords": ["notebook", "spiral bound", "college ruled", "stationery", "paper", "writing", "premium", "100 pages"],
      "description": "NotesCo premium spiral notebook, college ruled, 8.5x11 inches, containing 100 pages with a durable cover.",
      "color": "blue",
      "material": "paper, metal spiral"
    }
  ],
  "extracted_text": "0.5mm Black Ink PenBrand Ultra Smooth\n100 Pages NotesCo Premium College Ruled\nBuy Milk"
}

If no products OR other text are identifiable, return {"products": [], "extracted_text": null}.
Ensure your entire response is ONLY the JSON object.
PROMPT;

        $request_data = array(
            'contents' => array(
                array(
                    'parts' => array(
                        array(
                            'text' => $prompt
                        ),
                        array(
                            'inline_data' => array(
                                'mime_type' => $mime_type,
                                'data' => $base64_image
                            )
                        )
                    )
                )
            ),
            // Enhanced generation config for stricter JSON output
            'generationConfig' => array(
                'responseMimeType' => 'application/json',
                'temperature' => 0.1, // Lower temperature for more deterministic output
                'topP' => 0.95,
                'topK' => 40,
                'maxOutputTokens' => 2048 // Increased token limit for more detailed responses
            ),
            'safetySettings' => array(
                array(
                    'category' => 'HARM_CATEGORY_HARASSMENT',
                    'threshold' => 'BLOCK_NONE'
                ),
                array(
                    'category' => 'HARM_CATEGORY_HATE_SPEECH',
                    'threshold' => 'BLOCK_NONE'
                ),
                array(
                    'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
                    'threshold' => 'BLOCK_NONE'
                ),
                array(
                    'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
                    'threshold' => 'BLOCK_NONE'
                )
            )
        );

        $args = array(
            'method' => 'POST',
            'timeout' => 90, // Increased timeout for more complex image processing
            'headers' => array(
                'Content-Type' => 'application/json'
            ),
            'body' => json_encode($request_data)
        );

        return wp_remote_post($api_url, $args);
    }

    // Send WhatsApp notification for missing products
    private function send_whatsapp_notification($identified_items, $search_query = '') {
        // Double-check if notifications are enabled and WhatsApp number is set
        if (!$this->enable_notifications || empty($this->whatsapp_number)) {
            error_log("WC Gemini: WhatsApp notifications disabled or number not set.");
            return false;
        }

        // Format the message
        $message = "🔍 WooCommerce Gemini Alert: Customer searched for products not found in your store.\n\n";

        if (!empty($identified_items)) {
            $message .= "📋 Items identified in image:\n";
            foreach ($identified_items as $index => $item) {
                $item_name = !empty($item['product_name']) ? $item['product_name'] : 'Unknown Item';
                $brand = !empty($item['brand']) ? " ({$item['brand']})" : '';
                $message .= ($index + 1) . ". {$item_name}{$brand}\n";

                // Add detailed product information
                $details = array();
                if (!empty($item['type'])) $details[] = "Type: {$item['type']}";
                if (!empty($item['color'])) $details[] = "Color: {$item['color']}";
                if (!empty($item['material'])) $details[] = "Material: {$item['material']}";
                if (!empty($item['size'])) $details[] = "Size: {$item['size']}";

                if (!empty($details)) {
                    $message .= "   - " . implode("\n   - ", $details) . "\n";
                }

                // Add keywords if available
                if (!empty($item['keywords']) && is_array($item['keywords'])) {
                    $message .= "   - Keywords: " . implode(", ", $item['keywords']) . "\n";
                }

                // Add description if available
                if (!empty($item['description'])) {
                    $message .= "   - Description: {$item['description']}\n";
                }

                $message .= "\n"; // Add extra line between items
            }
        } elseif (!empty($search_query)) {
            $message .= "📝 Search text: {$search_query}\n";
        } else {
            $message .= "❓ Unknown item (no text or products identified)\n";
        }

        $message .= "\n🏪 Consider adding these products to your store inventory.\n";
        $message .= "💡 This notification was sent automatically because a customer was looking for these products.";


        // Format WhatsApp number (remove any non-numeric characters)
        $whatsapp_number = preg_replace('/[^0-9]/', '', $this->whatsapp_number);

        // Create WhatsApp URL
        $whatsapp_url = "https://api.whatsapp.com/send?phone={$whatsapp_number}&text=" . urlencode($message);

        // Log the notification
        error_log("WC Gemini: Sending WhatsApp notification to {$whatsapp_number} for missing products.");

        // Use wp_remote_get to trigger the notification without waiting for response
        // This is a simple way to send the notification without blocking the user experience
        wp_remote_get($whatsapp_url, array('timeout' => 0.01, 'blocking' => false));

        return true;
    }

    // Extract product information from Gemini API response (expects products array AND extracted_text)
    private function extract_product_info($gemini_response) {
        try {
            // Check for the text part containing the JSON string
            if (!isset($gemini_response['candidates'][0]['content']['parts'][0]['text'])) {
                 return array('error' => 'Unexpected API response structure: Missing text part.');
            }
            $text = $gemini_response['candidates'][0]['content']['parts'][0]['text'];

            // Decode the JSON string which should contain the {"products": [...], "extracted_text": "..."} structure
            $decoded_json = json_decode($text, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                 // ---> Pass raw text back on JSON error <---
                 return array('error' => 'Failed to decode JSON from Gemini response. Error: ' . json_last_error_msg(), 'raw_text' => $text);
            }

            // Check if the 'products' key exists and is an array (even if empty)
            if (!isset($decoded_json['products']) || !is_array($decoded_json['products'])) {
                 return array('error' => 'API response JSON does not contain the expected \'products\' array.', 'raw_response' => $decoded_json, 'raw_text' => $text);
            }
             // Check if 'extracted_text' key exists (can be string or null)
            // We don't strictly need to validate 'extracted_text' existence here, just ensure 'products' is okay.

            // Validate structure of each product item within the array (optional but good)
            $expected_keys = ['brand', 'product_name', 'type', 'size', 'visible_text', 'keywords', 'description', 'color', 'material'];
            foreach ($decoded_json['products'] as $index => $product_item) {
                if (!is_array($product_item)) {
                     return array('error' => "Item at index $index in 'products' array is not an object.", 'raw_response' => $decoded_json);
                }
                // Ensure all expected keys exist, set to null if missing
                foreach($expected_keys as $key) {
                    $decoded_json['products'][$index][$key] = $product_item[$key] ?? null; // Ensure keys exist
                }

                // Ensure keywords is always an array
                if (!isset($product_item['keywords']) || !is_array($product_item['keywords'])) {
                    $decoded_json['products'][$index]['keywords'] = [];
                }

                // Add color and material to keywords if they exist and are not null
                if (!empty($product_item['color'])) {
                    $color_keywords = explode(',', $product_item['color']);
                    foreach ($color_keywords as $color) {
                        $color = trim($color);
                        if (!empty($color) && !in_array($color, $decoded_json['products'][$index]['keywords'])) {
                            $decoded_json['products'][$index]['keywords'][] = $color;
                        }
                    }
                }

                if (!empty($product_item['material'])) {
                    $material_keywords = explode(',', $product_item['material']);
                    foreach ($material_keywords as $material) {
                        $material = trim($material);
                        if (!empty($material) && !in_array($material, $decoded_json['products'][$index]['keywords'])) {
                            $decoded_json['products'][$index]['keywords'][] = $material;
                        }
                    }
                }
            }

            // Return the whole decoded structure { "products": [...], "extracted_text": "..." }
            // The 'extracted_text' might not be present if Gemini failed, but 'products' should be.
            return $decoded_json;

        } catch (Exception $e) {
            return array('error' => 'Exception during response processing: ' . $e->getMessage(), 'raw_response' => $gemini_response);
        }
    }

    // Find matching products in WooCommerce using enhanced search algorithms
    private function find_matching_products($product_item_info) {
        $target_name = !empty($product_item_info['product_name']) ? trim($product_item_info['product_name']) : null;
        $brand = !empty($product_item_info['brand']) ? trim($product_item_info['brand']) : null;
        $type = !empty($product_item_info['type']) ? trim($product_item_info['type']) : null;
        $color = !empty($product_item_info['color']) ? trim($product_item_info['color']) : null;
        $material = !empty($product_item_info['material']) ? trim($product_item_info['material']) : null;
        $visible_text = !empty($product_item_info['visible_text']) ? trim($product_item_info['visible_text']) : null;

        if (!$target_name && !$visible_text) {
            error_log("WC Gemini Warning: No product_name or visible_text provided by Gemini. Cannot search.");
            return array('products' => [], 'type' => 'none'); // Return structure
        }

        // Normalization function with improved handling
        $normalize_name = function($name) {
            $name = strtolower($name);
            $name = str_replace(['_', '-', '/', '\\', '.', ',', ':', ';'], ' ', $name); // More characters replaced
            // More aggressive normalization for search query (keep accents, numbers, letters, spaces)
            $name = preg_replace('/[^\p{L}\p{N}\s]/u', '', $name);
            $name = preg_replace('/\s+/s', ' ', $name);
            return trim($name);
        };

        // Primary search query - use product name or visible text if name is not available
        $primary_search_term = $target_name ?: $visible_text;
        $normalized_search_query = $normalize_name($primary_search_term);

        if (empty($normalized_search_query)) {
             error_log("WC Gemini Warning: Normalized search query is empty. Cannot search.");
             return array('products' => [], 'type' => 'none');
        }

        $posts_per_page = 8; // Increased limit for better results

        // --- Step 1: Attempt Direct Search using Normalized Name ---
        $args_direct = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            's' => sanitize_text_field($normalized_search_query),
            'posts_per_page' => $posts_per_page,
            'orderby' => 'relevance',
        );

        // Add product category if type is provided
        if ($type) {
            $product_cat = get_term_by('name', $type, 'product_cat');
            if ($product_cat) {
                $args_direct['tax_query'] = array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $product_cat->term_id,
                    )
                );
            }
        }
        error_log("WC Gemini Debug: Performing DIRECT search with WP_Query args: " . print_r($args_direct, true));
        $query_direct = new WP_Query($args_direct);
        $matching_products = array();

        if ($query_direct->have_posts()) {
            error_log("WC Gemini Debug: DIRECT search found " . $query_direct->found_posts . " posts for query [$normalized_search_query].");
            while ($query_direct->have_posts()) {
                $query_direct->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                if (!$product) continue;
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                $matching_products[] = array(
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'price' => $product->get_price_html() ? $product->get_price_html() : 'Price not available',
                    'raw_price' => $product->get_price(),
                    'image' => $image_url,
                    'url' => $product->get_permalink(),
                    'add_to_cart_url' => $product->is_purchasable() && $product->is_in_stock() ? $product->add_to_cart_url() : null
                );
            }
            wp_reset_postdata();
            // If direct search yielded results, return them immediately
            if(!empty($matching_products)){
                error_log("WC Gemini Debug: Products returned by DIRECT search: " . print_r($matching_products, true));
                return array('products' => $matching_products, 'type' => 'direct'); // Return direct matches
            }
        }

        // --- Step 2: Fallback to Broader Search (if direct failed or returned empty) ---
        error_log("WC Gemini Debug: Direct search yielded no results for [$normalized_search_query]. Falling back to broader search using combined terms.");

        // Combine Brand, Keywords, and significant words for broader search
        $broad_search_terms = [];

        // Add brand if available (high priority)
        if ($brand) {
            $broad_search_terms[] = $brand;
        }

        // Add type/category if available (high priority)
        if ($type) {
            $broad_search_terms[] = $type;
        }

        // Add color if available (medium priority)
        if ($color) {
            $color_terms = explode(',', $color);
            foreach ($color_terms as $color_term) {
                $color_term = trim($color_term);
                if (!empty($color_term)) {
                    $broad_search_terms[] = $color_term;
                }
            }
        }

        // Add material if available (medium priority)
        if ($material) {
            $material_terms = explode(',', $material);
            foreach ($material_terms as $material_term) {
                $material_term = trim($material_term);
                if (!empty($material_term)) {
                    $broad_search_terms[] = $material_term;
                }
            }
        }

        // Add keywords if available (medium priority)
        if (!empty($product_item_info['keywords']) && is_array($product_item_info['keywords'])) {
            $broad_search_terms = array_merge($broad_search_terms, $product_item_info['keywords']);
        } else {
             error_log("WC Gemini Warning: No keywords provided by Gemini for '$target_name'.");
        }

        // Add significant words from normalized name (avoiding duplication with brand/keywords if possible)
        $name_words = explode(' ', $normalized_search_query);
        $significant_name_words = array_filter($name_words, function($w) { return strlen($w) > 2; });
        // Add only name words that aren't already likely covered by brand or keywords to avoid too much noise
        foreach ($significant_name_words as $word) {
            if (!in_array(strtolower($word), array_map('strtolower', $broad_search_terms))) {
                $broad_search_terms[] = $word;
            }
        }

        // Add visible text words if available (lower priority but still valuable)
        if ($visible_text) {
            $visible_text_words = explode(' ', $normalize_name($visible_text));
            $significant_visible_words = array_filter($visible_text_words, function($w) { return strlen($w) > 3; }); // Slightly longer words for visible text
            foreach ($significant_visible_words as $word) {
                if (!in_array(strtolower($word), array_map('strtolower', $broad_search_terms))) {
                    $broad_search_terms[] = $word;
                }
            }
        }

        // Clean up the combined terms: make unique, remove empty, trim whitespace
        $broad_search_terms = array_map('trim', $broad_search_terms);
        $broad_search_terms = array_values(array_unique(array_filter($broad_search_terms)));

        // Avoid empty broad search if no usable terms were found
        if(empty($broad_search_terms)) {
             error_log("WC Gemini Warning: Could not generate any broad search terms for '$target_name'.");
             return array('products' => [], 'type' => 'none');
        }

        $broad_search_query = implode(' ', $broad_search_terms);

        // Check if broad query is substantially different from direct query to avoid redundant search
        if (trim(strtolower($broad_search_query)) === trim(strtolower($normalized_search_query))) {
            error_log("WC Gemini Debug: Broad search query is identical to direct query. Skipping broad search.");
            return array('products' => [], 'type' => 'none'); // Already searched this
        }

        $args_broad = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            's' => sanitize_text_field($broad_search_query),
            'posts_per_page' => $posts_per_page + 5, // Fetch a few more for broader search
            'orderby' => 'relevance',
        );

        // Add product category if type is provided
        if ($type) {
            $product_cat = get_term_by('name', $type, 'product_cat');
            if ($product_cat) {
                $args_broad['tax_query'] = array(
                    array(
                        'taxonomy' => 'product_cat',
                        'field' => 'term_id',
                        'terms' => $product_cat->term_id,
                    )
                );
            }
        }
        error_log("WC Gemini Debug: Performing BROAD search with WP_Query args: " . print_r($args_broad, true));
        $query_broad = new WP_Query($args_broad);
        $matching_products = array(); // Reset for broad results

         if ($query_broad->have_posts()) {
            error_log("WC Gemini Debug: BROAD search found " . $query_broad->found_posts . " posts for query [$broad_search_query].");
             while ($query_broad->have_posts()) {
                 $query_broad->the_post();
                 $product_id = get_the_ID();
                 $product = wc_get_product($product_id);
                 if (!$product) continue;
                 $image_id = $product->get_image_id();
                 $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');
                 $matching_products[] = array(
                     'id' => $product_id,
                     'name' => $product->get_name(),
                     'price' => $product->get_price_html() ? $product->get_price_html() : 'Price not available',
                     'raw_price' => $product->get_price(),
                     'image' => $image_url,
                     'url' => $product->get_permalink(),
                     'add_to_cart_url' => $product->is_purchasable() && $product->is_in_stock() ? $product->add_to_cart_url() : null
                 );
             }
             wp_reset_postdata();
             error_log("WC Gemini Debug: Products returned by BROAD search: " . print_r($matching_products, true));
             return array('products' => $matching_products, 'type' => 'broad'); // Return broad matches
         } else {
             error_log("WC Gemini Debug: BROAD search also found 0 posts for query [$broad_search_query].");
             return array('products' => [], 'type' => 'none'); // No matches found at all
         }
    }

    // --- NEW: AJAX Handler for Adding Multiple Items to Cart ---
    public function ajax_add_multiple_to_cart() {
        check_ajax_referer('wc_gemini_nonce', 'nonce');

        // --- >> ADDED LOGGING: Log received items << ---
        error_log("WC Gemini Multi-Add AJAX: Received POST data: " . print_r($_POST, true));
        // --- << END LOGGING ---

        // Ensure WooCommerce functions are available and cart is loaded
        if (!function_exists('WC') || !WC()->cart || !WC()->session) { // Added session check here too
             error_log("WC Gemini Error: WooCommerce, WC Cart or WC Session not available during AJAX.");
             wp_send_json_error(array('message' => 'WooCommerce cart/session is not available.'));
             return;
        }
        // Ensure cart session is loaded, might be necessary for AJAX
        if (!WC()->session->has_session()) {
             WC()->session->set_customer_session_cookie(true);
             error_log("WC Gemini Debug: Cart session initialized during AJAX.");
        }

        if (!isset($_POST['items']) || !is_array($_POST['items']) || empty($_POST['items'])) {
            wp_send_json_error(array('message' => 'No items provided to add.'));
        }

        $items_to_add = $_POST['items'];
        $added_count = 0;
        $errors = array();

        foreach ($items_to_add as $item) {
            $product_id = isset($item['product_id']) ? absint($item['product_id']) : 0;
            $quantity = isset($item['quantity']) ? absint($item['quantity']) : 0;

            if ($product_id > 0 && $quantity > 0) {
                error_log("WC Gemini Debug: Attempting to add Product ID: $product_id, Quantity: $quantity");
                // Catch Throwable (PHP 7+) to potentially catch more fatal errors
                try {
                    $product = wc_get_product($product_id);
                    if (!$product) {
                         $errors[] = "Product (ID: $product_id) not found.";
                         error_log("WC Gemini Error: wc_get_product returned false for ID: $product_id");
                         continue;
                    }

                    if ($product->is_purchasable()) {
                         error_log("WC Gemini Debug: Product $product_id is purchasable. Calling add_to_cart...");
                         $result = WC()->cart->add_to_cart($product_id, $quantity);

                         if ($result === false) {
                             $errors[] = "Could not add '" . $product->get_name() . "' (ID: $product_id) - check stock or filters.";
                             error_log("WC Gemini Error: WC()->cart->add_to_cart returned false for ID: $product_id, Qty: $quantity");
                         } else {
                             error_log("WC Gemini Debug: Successfully added Product ID: $product_id, Qty: $quantity. Result key: $result");
                             $added_count++;
                         }
                     } else {
                         $errors[] = "Product '" . $product->get_name() . "' (ID: $product_id) is not purchasable.";
                          error_log("WC Gemini Warning: Product ID: $product_id is not purchasable.");
                     }
                 } catch (Throwable $t) { // Catch Throwable
                     error_log("WC Gemini AddToCart Throwable Caught: " . $t->getMessage() . " for Product ID: $product_id. File: " . $t->getFile() . " Line: " . $t->getLine());
                     $errors[] = "A server error occurred adding product ID $product_id: " . $t->getMessage();
                 }
             } else {
                 $errors[] = "Invalid product ID ($product_id) or quantity ($quantity) received.";
                 error_log("WC Gemini Error: Invalid product ID ($product_id) or quantity ($quantity) received.");
             }
        }

        // Prepare response data regardless of outcome
        $response_data = array();
        try {
             // Check if cart exists before calling methods on it
             if (WC()->cart) {
                $response_data['fragments'] = WC()->cart->get_refreshed_fragments();
                $response_data['cart_hash'] = WC()->cart->get_cart_hash();
             } else {
                 throw new Exception("Cart object not available for getting fragments.");
             }
        } catch (Throwable $t) { // Catch Throwable here too
             error_log("WC Gemini Error getting cart fragments/hash: " . $t->getMessage());
             $errors[] = "Failed to refresh cart data.";
             $response_data['fragments'] = null;
             $response_data['cart_hash'] = null;
        }

        if ($added_count > 0 && empty($errors)) {
            // Success
             wp_send_json_success($response_data);
         } elseif ($added_count > 0 && !empty($errors)) {
            // Partial success
             $response_data['message'] = "Added $added_count items, but encountered errors: " . implode('; ', $errors);
             wp_send_json_error($response_data);
        } else {
             // Complete failure
             $error_message = !empty($errors) ? implode('; ', $errors) : 'Unknown error adding items.';
             wp_send_json_error(array('message' => "Could not add any items to the cart. Errors: " . $error_message));
        }
    }
}

// Create CSS file placeholder
function wc_gemini_create_css_file() {
    $css_dir = plugin_dir_path(__FILE__) . 'assets/css';
    $css_file = $css_dir . '/style.css';

    if (!file_exists($css_dir)) {
        mkdir($css_dir, 0755, true);
    }

    // Create file only if it doesn't exist, don't overwrite
    if (!file_exists($css_file)) {
        $css_content = "/* WC Gemini Image Recognition Styles - Auto-generated placeholder */\n\n/* Add your styles here or replace this file */\n";
        file_put_contents($css_file, $css_content);
    }
}

// Create JS file placeholder (don't overwrite)
function wc_gemini_create_js_file() {
    $js_dir = plugin_dir_path(__FILE__) . 'assets/js';
    $js_file = $js_dir . '/script.js';

    if (!file_exists($js_dir)) {
        mkdir($js_dir, 0755, true);
    }

     // Create file only if it doesn't exist, don't overwrite
    if (!file_exists($js_file)) {
        $js_content = "jQuery(document).ready(function($) {\n    'use strict';\n    // WC Gemini Image Recognition Script - Auto-generated placeholder\n\n    // Add your JS logic here or replace this file\n\n});\n";
        file_put_contents($js_file, $js_content);
    }
}

// Initialize the plugin
function wc_gemini_init() {
    // Check if WooCommerce is active
    if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
        // Create needed directories and files
        wc_gemini_create_css_file();
        wc_gemini_create_js_file();

        // Initialize the plugin
        new WC_Gemini_Image_Recognition();
    }
}

add_action('plugins_loaded', 'wc_gemini_init');

