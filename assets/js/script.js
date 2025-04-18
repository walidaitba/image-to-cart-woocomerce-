jQuery(document).ready(function($) {
    'use strict';

    // --- Variables ---
    const modal = $('#wc-gemini-modal');
    const bubble = $('#wc-gemini-chat-bubble');
    const closeBtn = $('.wc-gemini-close');
    const imageInput = $('#wc-gemini-image-input');
    const imagePreview = $('#wc-gemini-image-preview');
    const analyzeBtn = $('#wc-gemini-analyze-btn');
    const resultsContainer = $('#wc-gemini-results');
    const loadingIndicator = $('#wc-gemini-loading');
    const step1 = $('#wc-gemini-step-1');
    const step2 = $('#wc-gemini-step-2');
    const ajaxUrl = wcGeminiParams.ajax_url;
    const nonce = wcGeminiParams.nonce;
    const currencySymbol = wcGeminiParams.currency_symbol || '$';
    const placeholderImage = wcGeminiParams.placeholder_image_url;

    let currentFile = null;

    // --- Helper Functions ---
    function formatPrice(rawPrice) {
        // Convert raw price (which might be string from WC) to float, default to 0 if invalid
        const price = parseFloat(rawPrice) || 0;
        // Basic price formatting - adapt if you need more complex formatting (e.g., decimals based on WC settings)
        return currencySymbol + price.toFixed(2);
    }

    // Format confidence level as percentage
    function formatConfidence(confidence) {
        if (confidence === undefined || confidence === null) return '';
        const confidenceValue = parseFloat(confidence) || 0;
        return `${Math.round(confidenceValue * 100)}%`;
    }

    function resetModal() {
        step1.addClass('active');
        step2.removeClass('active');
        imagePreview.empty().hide();
        resultsContainer.empty().hide(); // Clear previous results
        loadingIndicator.hide();
        analyzeBtn.prop('disabled', true);
        currentFile = null;
        imageInput.val(''); // Clear the file input
    }

    // --- Event Handlers ---

    // Open Modal
    bubble.on('click', function() {
        resetModal(); // Reset state every time modal opens
        modal.fadeIn();
    });

    // Close Modal
    closeBtn.on('click', function() {
        modal.fadeOut();
    });

    // Close modal if clicking outside the content area
    modal.on('click', function(event) {
        if ($(event.target).is(modal)) {
            modal.fadeOut();
        }
    });

    // Image Selection
    imageInput.on('change', function(event) {
        const file = event.target.files[0];
        if (file) {
            currentFile = file;
            const reader = new FileReader();
            reader.onload = function(e) {
                imagePreview.html('<img src="' + e.target.result + '" alt="Image Preview">');
                imagePreview.show();
                analyzeBtn.prop('disabled', false);
            }
            reader.readAsDataURL(file);
        } else {
            currentFile = null;
            imagePreview.empty().hide();
            analyzeBtn.prop('disabled', true);
        }
    });

    // Analyze Image Button Click
    analyzeBtn.on('click', function() {
        if (!currentFile) {
            alert('Please select an image first.');
            return;
        }

        step1.removeClass('active');
        step2.addClass('active');
        resultsContainer.empty().hide(); // Clear previous results before loading
        loadingIndicator.show();
        analyzeBtn.prop('disabled', true); // Disable button during analysis

        const formData = new FormData();
        formData.append('action', 'process_image');
        formData.append('nonce', nonce);
        formData.append('image', currentFile);

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: formData,
            processData: false, // Important!
            contentType: false, // Important!
            dataType: 'json', // Expect JSON response
            success: function(response) {
                loadingIndicator.hide();
                analyzeBtn.prop('disabled', false); // Re-enable on completion

                if (response.success) {
                    displayResults(response.data);
                } else {
                    resultsContainer.html('<p class="wc-gemini-error">Error: ' + (response.data.message || 'An unknown error occurred.') + '</p>').show();
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                loadingIndicator.hide();
                analyzeBtn.prop('disabled', false); // Re-enable on error
                console.error("AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                resultsContainer.html('<p class="wc-gemini-error">AJAX Error: Could not contact server. ' + textStatus + '</p>').show();
            }
        });
    });

    // --- Result Display & Interaction ---

    function displayResults(data) {
        resultsContainer.empty(); // Clear previous results

        // --- Display Raw Gemini Output for Debugging (hidden by default) ---
        if (data.gemini_raw_output) {
            // Escape HTML to prevent potential XSS if the output contains HTML-like text
            const escapedOutput = $('<div />').text(data.gemini_raw_output).html();
            resultsContainer.append(
                '<div class="wc-gemini-debug-output" style="display:none;">' +
                '<h4>Gemini Raw Output: <button class="wc-gemini-toggle-debug">Show/Hide</button></h4>' +
                '<pre>' + escapedOutput + '</pre>' +
                '</div><hr/>'
            );

            // Add toggle functionality for debug output
            $('.wc-gemini-toggle-debug').on('click', function() {
                $(this).closest('.wc-gemini-debug-output').find('pre').toggle();
            });
        }

        // Display identified items from the image
        if (data.identified_items && data.identified_items.length > 0) {
            const identifiedItemsSection = $('<div class="wc-gemini-identified-items"></div>');
            identifiedItemsSection.append('<h3>Items Identified in Your Image:</h3>');

            const itemsList = $('<ul class="wc-gemini-identified-list"></ul>');

            data.identified_items.forEach(item => {
                const itemName = item.product_name || 'Unknown Item';
                const itemBrand = item.brand ? `<span class="wc-gemini-item-brand">${item.brand}</span>` : '';
                const itemType = item.type ? `<span class="wc-gemini-item-type">${item.type}</span>` : '';
                const itemColor = item.color ? `<span class="wc-gemini-item-color">Color: ${item.color}</span>` : '';
                const itemMaterial = item.material ? `<span class="wc-gemini-item-material">Material: ${item.material}</span>` : '';

                itemsList.append(`
                    <li class="wc-gemini-identified-item">
                        <div class="wc-gemini-item-name">${itemName} ${itemBrand}</div>
                        <div class="wc-gemini-item-details">
                            ${itemType} ${itemColor} ${itemMaterial}
                        </div>
                    </li>
                `);
            });

            identifiedItemsSection.append(itemsList);
            resultsContainer.append(identifiedItemsSection);
            resultsContainer.append('<hr/>');
        }

        if (!data.matching_products || data.matching_products.length === 0) {
            // Create a container for the no-matches message and WhatsApp button
            const noMatchesContainer = $('<div class="wc-gemini-no-matches-container"></div>');

            // Add the no-matches message
            noMatchesContainer.append('<p class="wc-gemini-no-matches">No matching products found in the store for the items identified.</p>');

            // Add the WhatsApp button with detailed information
            const whatsappBtn = $('<a href="#" class="wc-gemini-whatsapp-btn"><i class="wc-gemini-whatsapp-icon">📱</i> Notify Store Owner</a>');

            // Create a detailed message for WhatsApp
            let whatsappMessage = "Hello! I'm interested in products that aren't currently in your store.\n\n";

            // Add identified items to the message
            if (data.identified_items && data.identified_items.length > 0) {
                whatsappMessage += "Items I'm looking for:\n";
                data.identified_items.forEach((item, index) => {
                    const itemName = item.product_name || 'Unknown Item';
                    const brand = item.brand ? ` (${item.brand})` : '';
                    const type = item.type ? ` - Type: ${item.type}` : '';
                    const color = item.color ? ` - Color: ${item.color}` : '';
                    const material = item.material ? ` - Material: ${item.material}` : '';
                    whatsappMessage += `${index + 1}. ${itemName}${brand}${type}${color}${material}\n`;
                });
            }

            // Add extracted text if available
            if (data.extracted_text) {
                whatsappMessage += "\nText from image: " + data.extracted_text;
            } else if (data.gemini_raw_output) {
                whatsappMessage += "\nRaw text from image: " + data.gemini_raw_output.substring(0, 200) + (data.gemini_raw_output.length > 200 ? '...' : '');
            }

            whatsappMessage += "\n\nPlease let me know if you can add these products to your store. Thank you!";

            // Set up WhatsApp button click handler
            whatsappBtn.on('click', function(e) {
                e.preventDefault();
                // Get the store's WhatsApp number from the global params
                const storeWhatsappNumber = wcGeminiParams.store_whatsapp_number || '';

                if (storeWhatsappNumber) {
                    // Open WhatsApp with the pre-filled message
                    window.open(`https://api.whatsapp.com/send?phone=${storeWhatsappNumber}&text=${encodeURIComponent(whatsappMessage)}`, '_blank');
                } else {
                    alert('The store owner has not set up a WhatsApp contact number yet.');
                }
            });

            // Add the button to the container
            noMatchesContainer.append(whatsappBtn);

            // Add the container to the results
            resultsContainer.append(noMatchesContainer);
            resultsContainer.show();
            return;
        }

        // Add a title based on search type
        let title = 'Found these matching products:';
        if (data.overall_search_type === 'broad') {
            title = 'Found possible matches based on your image:';
        } else if (data.overall_search_type === 'text_search') {
            title = 'Found products based on text in your image:';
        }
        resultsContainer.append('<h3>' + title + '</h3>');

        const productList = $('<ul class="wc-gemini-product-list"></ul>');

        data.matching_products.forEach(product => {
            const imageUrl = product.image || placeholderImage;
            // Use raw_price for calculations, formatted price for display initially
            const displayPrice = product.price || formatPrice(product.raw_price);
            const rawPrice = product.raw_price || 0; // Default raw price to 0 if missing

            // Get match confidence if available
            const matchConfidence = product.match_confidence ? formatConfidence(product.match_confidence) : '';
            const matchType = product.search_type_origin || data.overall_search_type || 'direct';
            let matchBadge = '';

            // Create a badge based on match type
            if (matchType === 'direct') {
                matchBadge = '<span class="wc-gemini-match-badge direct">Best Match</span>';
            } else if (matchType === 'broad') {
                matchBadge = '<span class="wc-gemini-match-badge broad">Possible Match</span>';
            } else if (matchType === 'text_search') {
                matchBadge = '<span class="wc-gemini-match-badge text">Text Match</span>';
            }

            const listItem = $(`
                <li class="wc-gemini-product-item">
                    <div class="wc-gemini-product-image">
                        <img src="${imageUrl}" alt="${product.name}" />
                        ${matchBadge}
                    </div>
                    <div class="wc-gemini-product-details">
                        <span class="wc-gemini-product-name">${product.name}</span>
                        <span class="wc-gemini-product-price">${displayPrice}</span>
                        <div class="wc-gemini-product-actions">
                            <a href="${product.url}" target="_blank" class="wc-gemini-view-product">View Product</a>
                            ${matchConfidence ? `<span class="wc-gemini-match-confidence">Match: ${matchConfidence}</span>` : ''}
                        </div>
                    </div>
                    <div class="wc-gemini-product-select">
                         <input type="checkbox" class="wc-gemini-product-checkbox"
                                data-product-id="${product.id}"
                                data-raw-price="${rawPrice}"
                                id="wc-gemini-product-${product.id}">
                         <label for="wc-gemini-product-${product.id}">Add to Cart</label>
                         <div class="wc-gemini-quantity-wrapper">
                             <label for="wc-gemini-qty-${product.id}" class="wc-gemini-qty-label">Qty:</label>
                             <input type="number" class="wc-gemini-product-quantity"
                                    value="1" min="1" step="1"
                                    id="wc-gemini-qty-${product.id}"
                                    data-product-id="${product.id}" disabled>
                         </div>
                    </div>
                </li>
            `);
            productList.append(listItem);
        });

        resultsContainer.append(productList);

        // Add Total Price Display and Add to Cart Button
        resultsContainer.append(`
            <div class="wc-gemini-summary">
                <div class="wc-gemini-total-price">
                    Total: <span id="wc-gemini-total-price-value">${formatPrice(0)}</span>
                </div>
                <button id="wc-gemini-add-all-btn" class="wc-gemini-button" disabled>Add Selected to Cart</button>
                <div id="wc-gemini-add-to-cart-status"></div>
            </div>
        `);

        resultsContainer.show();

        // Add event listeners after elements are added to DOM
        $('.wc-gemini-product-checkbox').on('change', updateTotalPriceAndButton);
        $('#wc-gemini-add-all-btn').on('click', addSelectedToCart);
    }

    function updateTotalPriceAndButton() {
        let totalPrice = 0;
        let itemsSelected = 0;
        $('.wc-gemini-product-checkbox:checked').each(function() {
            const $checkbox = $(this);
            const productId = $checkbox.data('product-id');
            const $quantityInput = $('#wc-gemini-qty-' + productId);
            const quantity = parseInt($quantityInput.val(), 10) || 1; // Get quantity, default to 1

            const rawPrice = parseFloat($checkbox.data('raw-price')) || 0;
            totalPrice += rawPrice * quantity; // Multiply price by quantity
            itemsSelected++;
            $quantityInput.prop('disabled', false); // Enable quantity input
        });

        // Disable quantity inputs for unchecked items
        $('.wc-gemini-product-checkbox:not(:checked)').each(function() {
             const productId = $(this).data('product-id');
             $('#wc-gemini-qty-' + productId).prop('disabled', true);
        });

        $('#wc-gemini-total-price-value').text(formatPrice(totalPrice));
        $('#wc-gemini-add-all-btn').prop('disabled', itemsSelected === 0);
    }

    function addSelectedToCart() {
        const itemsToAdd = [];
        $('.wc-gemini-product-checkbox:checked').each(function() {
            const productId = $(this).data('product-id');
            const quantity = parseInt($('#wc-gemini-qty-' + productId).val(), 10) || 1;
            itemsToAdd.push({
                product_id: productId,
                quantity: quantity // Use selected quantity
            });
        });

        if (itemsToAdd.length === 0) {
            alert('Please select at least one product to add.');
            return;
        }

        const addButton = $('#wc-gemini-add-all-btn');
        const statusDiv = $('#wc-gemini-add-to-cart-status');
        addButton.prop('disabled', true).text('Adding...');
        statusDiv.text('').removeClass('success error'); // Clear previous status

        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            data: {
                action: 'wc_gemini_add_multiple_to_cart',
                nonce: nonce,
                items: itemsToAdd
            },
            dataType: 'json',
            success: function(response) {
                 if (response.success) {
                    statusDiv.text('Successfully added to cart!').addClass('success');
                     // Refresh cart fragments (e.g., mini cart) using WooCommerce events/AJAX response
                     if (response.data && response.data.fragments) {
                         $.each(response.data.fragments, function(key, value) {
                             $(key).replaceWith(value);
                         });
                         // Trigger standard WooCommerce events
                         $(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash, addButton]);
                         $(document.body).trigger('wc_cart_changed'); // General cart change event
                     }
                    // Refresh the page immediately on success
                    location.reload();
                } else {
                    statusDiv.text('Error: ' + (response.data.message || 'Could not add items.')).addClass('error');
                     addButton.prop('disabled', itemsToAdd.length === 0).text('Add Selected to Cart'); // Re-enable if selection still valid
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.error("Add to Cart AJAX Error:", textStatus, errorThrown, jqXHR.responseText);
                statusDiv.text('AJAX Error: Could not add items. ' + textStatus).addClass('error');
                addButton.prop('disabled', itemsToAdd.length === 0).text('Add Selected to Cart'); // Re-enable if selection still valid
            }
        });
    }

}); // End jQuery document ready