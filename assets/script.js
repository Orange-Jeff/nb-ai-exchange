/**
 * NB AI Exchange - JavaScript
 * Version 1.2.0 - Added AI Image Import
 */
(function($) {
    'use strict';

    let currentShortcodeCode = '';
    let currentExportType = 'shortcode';

    $(document).ready(function() {
        loadShortcodes();
        initExportPanel();
        initImportPanel();
        initImagesTab();
        initShortcodesTab();
        initHistoryTab();
        initModal();
    });

    // ========================================
    // Export Panel
    // ========================================

    function loadShortcodes() {
        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_get_shortcodes',
            nonce: nbAI.nonce
        }, function(response) {
            if (response.success) {
                const $select = $('#shortcodeSelect');
                $select.empty().append('<option value="">-- Select a shortcode --</option>');

                response.data.shortcodes.forEach(sc => {
                    const label = `[${sc.tag}] - ${sc.source}`;
                    $select.append(`<option value="${sc.tag}">${label}</option>`);
                });

                // Also populate the shortcodes tab if it exists
                renderAllShortcodes(response.data.shortcodes);
            }
        });
    }

    function initExportPanel() {
        // Export type change
        $('#exportType').on('change', function() {
            currentExportType = $(this).val();
            const isShortcode = currentExportType === 'shortcode';

            $('#shortcodeSelectRow').toggle(isShortcode);
            $('#pasteCodeRow').toggle(!isShortcode);

            if (!isShortcode) {
                currentShortcodeCode = '';
                $('#exportText').val('');
                $('#copyToClipboard').prop('disabled', true);
            }

            updateExportText();
        });

        // Paste code for non-shortcode types
        $('#pasteCode').on('input', function() {
            currentShortcodeCode = $(this).val();
            updateExportText();
            $('#copyToClipboard').prop('disabled', !currentShortcodeCode.trim());
        });

        // Shortcode selection
        $('#shortcodeSelect').on('change', function() {
            const tag = $(this).val();
            if (tag) {
                loadShortcodeSource(tag);
            } else {
                $('#exportText').val('');
                $('#copyToClipboard').prop('disabled', true);
            }
        });

        // Refresh button
        $('#refreshShortcodes').on('click', loadShortcodes);

        // Prompt template change
        $('#promptTemplate').on('change', function() {
            const val = $(this).val();
            $('#customPromptRow').toggle(val === 'custom');
            updateExportText();
        });

        // Custom prompt change
        $('#customPrompt').on('input', updateExportText);

        // Context checkbox change
        $('#includeContext').on('change', updateExportText);

        // Copy button
        $('#copyToClipboard').on('click', function() {
            const text = $('#exportText').val();
            navigator.clipboard.writeText(text).then(() => {
                $('#copyStatus').html('<span class="dashicons dashicons-yes" style="color:green;"></span> Copied!');
                setTimeout(() => $('#copyStatus').html(''), 2000);

                // Save to history
                const label = currentExportType === 'shortcode' ? $('#shortcodeSelect').val() : currentExportType;
                saveExchange(label, text, '');
            });
            });
        });
    }

    function loadShortcodeSource(tag) {
        $('#exportText').val('Loading...');

        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_get_shortcode_source',
            nonce: nbAI.nonce,
            tag: tag
        }, function(response) {
            if (response.success) {
                currentShortcodeCode = response.data.code;
                updateExportText();
                $('#copyToClipboard').prop('disabled', false);
            } else {
                $('#exportText').val('Error: ' + response.data.message);
                currentShortcodeCode = '';
            }
        });
    }

    function updateExportText() {
        const template = $('#promptTemplate').val();
        const includeContext = $('#includeContext').is(':checked');
        const exportType = $('#exportType').val();

        // For create-new prompts, we don't need existing code
        const isCreateNew = template.startsWith('create-') || template.startsWith('divi-');

        if (!currentShortcodeCode && !isCreateNew) {
            $('#exportText').val('');
            return;
        }

        let prompt = getPromptText(template);
        let contextBlock = '';
        let codeBlock = '';
        let codeType = 'php';

        // Build context based on export type
        if (includeContext) {
            contextBlock = `**Environment:**\n- WordPress ${nbAI.wpVersion}, PHP ${nbAI.phpVersion}`;

            if (nbAI.hasDivi) {
                contextBlock += `\n- Divi Theme/Builder ${nbAI.diviVersion || 'installed'}`;
            }

            // Add type-specific context
            switch (exportType) {
                case 'shortcode':
                    contextBlock += `\n- Shortcodes: registered with \`add_shortcode('tag', callback)\`, used as \`[tag]\` in content
- Callback receives \`$atts\` (array) and \`$content\` (string), must RETURN HTML (not echo)`;
                    break;
                case 'css':
                    contextBlock += `\n- CSS can go in: Divi Theme Customizer, WP Additional CSS, or child theme style.css
- Divi uses .et_pb_* classes for modules`;
                    codeType = 'css';
                    break;
                case 'code-module':
                    contextBlock += `\n- Divi Code Module accepts HTML, CSS (in <style> tags), and JS (in <script> tags)
- Code runs in the page context, can use jQuery`;
                    codeType = 'html';
                    break;
                case 'theme-builder':
                    contextBlock += `\n- Divi Theme Builder uses JSON for layouts
- Can export/import via Divi → Theme Builder → Portability`;
                    codeType = 'json';
                    break;
            }
            contextBlock += '\n\n';
        }

        // Build code block if we have code
        if (currentShortcodeCode) {
            const label = exportType === 'shortcode' ? `[${$('#shortcodeSelect').val()}]` : exportType.toUpperCase();
            codeBlock = `**Current Code (${label}):**
\`\`\`${codeType}
${currentShortcodeCode}
\`\`\`

`;
        }

        // Build the request
        let request = 'Please provide the complete code in a properly formatted code block.';
        if (exportType === 'css') {
            request = 'Please provide the complete CSS in a css code block.';
        } else if (exportType === 'code-module') {
            request = 'Please provide the complete code module content (HTML/CSS/JS) ready to paste into Divi.';
        }

        const exportText = `${prompt}

${contextBlock}${codeBlock}${request}`;

        $('#exportText').val(exportText.trim());
        $('#copyToClipboard').prop('disabled', !exportText.trim() || (!currentShortcodeCode && !isCreateNew));
    }

    function getPromptText(template) {
        const prompts = {
            // General
            analyze: "Please analyze this code. Explain what it does, identify any issues, and suggest improvements.",
            improve: "Please improve this code. Make it more efficient, add better error handling, and follow best practices. Return the complete improved code.",
            explain: "Please explain how this code works step by step. I'm trying to understand it.",
            bugs: "Please review this code for potential bugs, security issues, or problems. List any issues you find.",

            // Styling
            style: "Please improve the CSS/styling. Make it look more professional and modern.",
            responsive: "Please make this code responsive for mobile, tablet, and desktop. Add appropriate media queries.",
            'dark-mode': "Please add dark mode support to this CSS using prefers-color-scheme or a .dark-mode class toggle.",

            // Divi specific
            'divi-blog': "Please create CSS to style the Divi Blog Module. I want a modern card-based layout with hover effects. Target .et_pb_blog_grid and related classes.",
            'divi-posts': "Please create CSS to style Divi post layouts. Include styling for post titles, meta, featured images, and content areas.",
            'divi-header': "Please create a Divi Code Module for a custom header section. Include HTML structure and CSS styling for a modern header with logo area, navigation placeholder, and CTA button.",
            'divi-footer': "Please create a Divi Code Module for a custom footer. Include columns for about, links, contact info, and social icons with modern styling.",

            // Create new
            'create-shortcode': "Please create a new WordPress shortcode. I need: [describe what you need]. Include the complete add_shortcode() registration and callback function.",
            'create-css': "Please create CSS for: [describe the styling you need]. Make it clean, modern, and well-commented.",

            custom: $('#customPrompt').val() || "Please review this code:"
        };
        return prompts[template] || prompts.analyze;
    }

    // ========================================
    // Import Panel
    // ========================================

    function initImportPanel() {
        // Parse button
        $('#parseResponse').on('click', parseAIResponse);

        // Install button
        $('#installShortcode').on('click', installShortcode);

        // Copy extracted code button
        $('#copyExtracted').on('click', function() {
            const code = $('#extractedCode').text();
            navigator.clipboard.writeText(code).then(() => {
                $(this).html('<span class="dashicons dashicons-yes"></span> Copied!');
                setTimeout(() => $(this).html('<span class="dashicons dashicons-clipboard"></span> Copy Code'), 1500);
            });
        });

        // Auto-detect code on paste
        $('#importText').on('paste', function() {
            setTimeout(parseAIResponse, 100);
        });

        // Import code type changes
        $('#importCodeType, #targetUsage').on('change', updateImportHint);
        updateImportHint(); // Initial state
    }

    function updateImportHint() {
        const codeType = $('#importCodeType').val();
        const target = $('#targetUsage').val();
        const $hint = $('#importTypeHint');
        const $hintText = $('#hintText');
        const $tagRow = $('#shortcodeTagRow');
        const $installBtn = $('#installShortcode');
        const $copyBtn = $('#copyExtracted');

        // Remove all hint classes
        $hint.removeClass('hint-update hint-snippet hint-divi hint-css');

        let text = '';
        let showTagField = false;
        let showInstallBtn = false;
        let showCopyBtn = true;
        let buttonText = '';

        // Code type hints
        switch (codeType) {
            case 'shortcode':
                text = 'This will register a <strong>PHP shortcode</strong> in the database. Use <code>[tag]</code> in any content.';
                showTagField = true;
                showInstallBtn = true;
                showCopyBtn = false;
                buttonText = '<span class="dashicons dashicons-yes"></span> Install Shortcode';
                break;
            case 'css':
                text = '<strong>CSS code</strong> - Copy and paste into your chosen destination.';
                $hint.addClass('hint-css');
                break;
            case 'code-module':
                text = '<strong>Divi Code Module</strong> - Copy the HTML/CSS/JS and paste into a Code Module in Divi Builder.';
                $hint.addClass('hint-divi');
                break;
            case 'theme-builder':
                text = '<strong>Theme Builder JSON</strong> - Import via Divi → Theme Builder → Portability → Import.';
                $hint.addClass('hint-divi');
                break;
            case 'snippet':
                text = '<strong>Reference only</strong> - This will be saved to history for future reference.';
                $hint.addClass('hint-snippet');
                showInstallBtn = true;
                buttonText = '<span class="dashicons dashicons-archive"></span> Save to History';
                break;
        }

        // Add destination hints
        switch (target) {
            case 'divi-customizer':
                text += '<br>📍 Go to: <strong>Divi → Theme Customizer → General Settings → Custom CSS</strong>';
                break;
            case 'divi-code':
                text += '<br>📍 In Divi Builder: Add <strong>Code Module</strong> and paste the code.';
                break;
            case 'divi-builder':
                text += '<br>📍 Go to: <strong>Divi → Theme Builder → Portability (↔ icon) → Import</strong>';
                break;
            case 'wp-customizer':
                text += '<br>📍 Go to: <strong>Appearance → Customize → Additional CSS</strong>';
                break;
            case 'child-theme':
                text += '<br>📍 Add to your <strong>child theme\'s style.css</strong> file via FTP or theme editor.';
                break;
        }

        $hintText.html(text);
        $tagRow.toggle(showTagField);
        $installBtn.toggle(showInstallBtn).html(buttonText);
        $copyBtn.toggle(showCopyBtn && $('#extractedCode').text().length > 0);
    }

    function parseAIResponse() {
        const text = $('#importText').val();
        if (!text.trim()) {
            showStatus('Please paste the AI response first', 'error');
            return;
        }

        // Try to extract code from various formats
        const result = extractCode(text);

        if (result.code) {
            $('#extractedCode').text(result.code);
            $('#codePreview').show();

            // Auto-detect code type
            if (result.type === 'css') {
                $('#importCodeType').val('css');
            } else if (result.type === 'html' || result.code.includes('<style>') || result.code.includes('<script>')) {
                $('#importCodeType').val('code-module');
            } else if (result.code.includes('add_shortcode')) {
                $('#importCodeType').val('shortcode');
                // Extract shortcode tag
                const tagMatch = result.code.match(/add_shortcode\s*\(\s*['"]([^'"]+)['"]/);
                if (tagMatch) {
                    $('#shortcodeTag').val(tagMatch[1]);
                }
            }

            updateImportHint();
            $('#installShortcode, #copyExtracted').prop('disabled', false);
            showStatus(`${result.type.toUpperCase()} code extracted! Review below.`, 'success');
        } else {
            // Maybe it's just text/explanation
            $('#codePreview').hide();
            $('#installShortcode').prop('disabled', true);
            $('#copyExtracted').hide();
            showStatus('Could not find code blocks in the response. Make sure the AI included a code block with ``` markers.', 'error');
        }
    }

    function extractCode(text) {
        // Try CSS code block
        const cssMatch = text.match(/```css\s*([\s\S]*?)```/i);
        if (cssMatch) {
            return { code: cssMatch[1].trim(), type: 'css' };
        }

        // Try HTML code block
        const htmlMatch = text.match(/```html\s*([\s\S]*?)```/i);
        if (htmlMatch) {
            return { code: htmlMatch[1].trim(), type: 'html' };
        }

        // Try PHP code block
        const phpMatch = text.match(/```php\s*([\s\S]*?)```/i);
        if (phpMatch) {
            return { code: cleanCode(phpMatch[1]), type: 'php' };
        }

        // Try JSON code block
        const jsonMatch = text.match(/```json\s*([\s\S]*?)```/i);
        if (jsonMatch) {
            return { code: jsonMatch[1].trim(), type: 'json' };
        }

        // Try generic code block
        const genericMatch = text.match(/```\s*([\s\S]*?)```/);
        if (genericMatch) {
            const code = genericMatch[1].trim();
            // Try to detect type from content
            if (code.match(/^\s*[.#@]/m) || code.includes('{') && code.includes(':') && !code.includes('function')) {
                return { code: code, type: 'css' };
            }
            if (code.includes('<') && code.includes('>')) {
                return { code: code, type: 'html' };
            }
            return { code: cleanCode(code), type: 'php' };
        }

        return { code: null, type: null };
    }

    function cleanCode(code) {
        // Remove <?php if present at start
        code = code.replace(/^<\?php\s*/i, '');
        // Remove ?> if present at end
        code = code.replace(/\?>\s*$/i, '');
        return code.trim();
    }

    function installShortcode() {
        const codeType = $('#importCodeType').val();
        const target = $('#targetUsage').val();
        const tag = $('#shortcodeTag').val().trim();
        const code = $('#extractedCode').text();
        const description = $('#shortcodeDesc').val().trim();

        // Handle non-shortcode types - just save to history
        if (codeType !== 'shortcode') {
            const label = description || codeType;
            saveExchange(label, '', code);
            showStatus(`✓ ${codeType.toUpperCase()} code saved to history. Use "Copy Code" to grab it.`, 'success');
            return;
        }

        // Shortcode installation
        if (!tag) {
            showStatus('Please enter a shortcode tag', 'error');
            return;
        }

        if (!tag.match(/^[a-z0-9_-]+$/i)) {
            showStatus('Shortcode tag can only contain letters, numbers, hyphens, and underscores', 'error');
            return;
        }

        $('#installShortcode').prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Installing...');

        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_install_shortcode',
            nonce: nbAI.nonce,
            tag: tag,
            code: code,
            description: description,
            target: target
        }, function(response) {
            updateImportHint(); // Reset button text

            if (response.success) {
                showStatus(`✓ ${response.data.message}<br><strong>Usage:</strong> <code>[${tag}]</code>`, 'success');

                // Save to history
                saveExchange(tag, '', code);

                // Clear form
                setTimeout(() => {
                    $('#importText').val('');
                    $('#shortcodeTag').val('');
                    $('#shortcodeDesc').val('');
                    $('#codePreview').hide();
                    $('#installShortcode').prop('disabled', true);
                    $('#copyExtracted').hide();
                }, 3000);

                // Refresh shortcode list
                loadShortcodes();
            } else {
                showStatus('✗ ' + response.data.message, 'error');
            }
        });
    }

    function showStatus(message, type) {
        const $status = $('#installStatus');
        let noticeClass = 'notice-success';
        if (type === 'error') noticeClass = 'notice-error';
        if (type === 'warning') noticeClass = 'notice-warning';
        $status.html(`<div class="notice ${noticeClass} inline"><p>${message}</p></div>`);
        setTimeout(() => $status.html(''), type === 'success' ? 8000 : 5000);
    }

    function saveExchange(tag, exportText, importText) {
        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_save_exchange',
            nonce: nbAI.nonce,
            tag: tag,
            export: exportText,
            import: importText
        });
    }

    // ========================================
    // Shortcodes Tab
    // ========================================

    function initShortcodesTab() {
        // View code buttons
        $(document).on('click', '.view-code', function() {
            const tag = $(this).data('tag');
            loadAndShowCode(tag);
        });

        // Delete shortcode buttons
        $(document).on('click', '.delete-shortcode', function() {
            const tag = $(this).data('tag');
            if (confirm(`Delete shortcode [${tag}]? This cannot be undone.`)) {
                deleteShortcode(tag, $(this).closest('tr'));
            }
        });
    }

    function renderAllShortcodes(shortcodes) {
        const $list = $('#allShortcodesList');
        if (!$list.length) return;

        if (!shortcodes.length) {
            $list.html('<p class="nb-empty">No nb-* shortcodes found.</p>');
            return;
        }

        let html = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Shortcode</th><th>Source</th><th>Actions</th></tr></thead><tbody>';

        shortcodes.forEach(sc => {
            html += `<tr>
                <td><code>[${sc.tag}]</code></td>
                <td>${sc.source}${sc.file ? ' - ' + sc.file.split('/').pop() : ''}</td>
                <td>
                    <button type="button" class="button button-small view-code" data-tag="${sc.tag}">View Code</button>
                    <button type="button" class="button button-small copy-tag" data-tag="${sc.tag}">Copy Tag</button>
                </td>
            </tr>`;
        });

        html += '</tbody></table>';
        $list.html(html);

        // Copy tag handler
        $(document).on('click', '.copy-tag', function() {
            const tag = '[' + $(this).data('tag') + ']';
            navigator.clipboard.writeText(tag);
            $(this).html('Copied!');
            setTimeout(() => $(this).html('Copy Tag'), 1000);
        });
    }

    function loadAndShowCode(tag) {
        $('#modalTitle').text(`[${tag}] Code`);
        $('#modalCode').text('Loading...');
        $('#codeModal').show();

        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_get_shortcode_source',
            nonce: nbAI.nonce,
            tag: tag
        }, function(response) {
            if (response.success) {
                $('#modalCode').text(response.data.code);
            } else {
                $('#modalCode').text('Error: ' + response.data.message);
            }
        });
    }

    function deleteShortcode(tag, $row) {
        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_delete_custom_shortcode',
            nonce: nbAI.nonce,
            tag: tag
        }, function(response) {
            if (response.success) {
                $row.fadeOut(() => $row.remove());
            } else {
                alert('Error: ' + response.data.message);
            }
        });
    }

    // ========================================
    // Images Tab
    // ========================================

    function initImagesTab() {
        const $input = $('#imageInput');
        const $preview = $('#imagePreviewBox');
        const $importBtn = $('#importImage');
        const $previewBtn = $('#previewImage');
        const $status = $('#imageImportStatus');
        const $info = $('#imageInfo');

        if (!$input.length) return;

        // Preview button
        $previewBtn.on('click', function() {
            previewImage();
        });

        // Auto-preview on paste
        $input.on('paste', function() {
            setTimeout(previewImage, 100);
        });

        // Import button
        $importBtn.on('click', function() {
            importImage();
        });
    }

    function previewImage() {
        const input = $('#imageInput').val().trim();
        const $preview = $('#imagePreviewBox');
        const $importBtn = $('#importImage');
        const $info = $('#imageInfo');
        const $status = $('#imageImportStatus');

        $status.html('');

        if (!input) {
            $preview.html('<div class="nb-preview-placeholder"><span class="dashicons dashicons-format-image"></span><p>Image preview will appear here</p></div>');
            $importBtn.prop('disabled', true);
            $info.hide();
            return;
        }

        // Detect input type
        let imgSrc = '';
        let inputType = '';
        let source = 'Unknown';

        // Check for data URI
        if (input.match(/^data:image\/(png|jpg|jpeg|gif|webp);base64,/i)) {
            imgSrc = input;
            inputType = 'Base64 Data URI';
            source = 'Base64 Data';
        }
        // Check for raw base64 (long string of valid base64 chars)
        else if (input.match(/^[A-Za-z0-9+\/=]+$/) && input.length > 100) {
            imgSrc = 'data:image/png;base64,' + input;
            inputType = 'Raw Base64';
            source = 'Base64 Data';
        }
        // Check for URL
        else if (input.match(/^https?:\/\//i)) {
            imgSrc = input;
            inputType = 'URL';
            source = detectAISource(input);
        }
        else {
            $preview.html('<div class="nb-preview-placeholder nb-preview-error"><span class="dashicons dashicons-warning"></span><p>Could not recognize input format</p></div>');
            $importBtn.prop('disabled', true);
            return;
        }

        // Show loading state
        $preview.html('<div class="nb-preview-placeholder"><span class="dashicons dashicons-update spin"></span><p>Loading preview...</p></div>');

        // Create image element
        const img = new Image();
        img.onload = function() {
            $preview.html(img);
            $info.show();
            $('#imageType').text(inputType);
            $('#imageSource').text(source);
            $importBtn.prop('disabled', false);
            $status.html('<span class="dashicons dashicons-yes" style="color:green;"></span> Image loaded! Ready to import.');
        };
        img.onerror = function() {
            $preview.html('<div class="nb-preview-placeholder nb-preview-error"><span class="dashicons dashicons-no"></span><p>Failed to load image. URL may be expired or blocked.</p></div>');
            $info.hide();
            $importBtn.prop('disabled', true);
        };
        img.src = imgSrc;
    }

    function detectAISource(url) {
        const urlLower = url.toLowerCase();

        if (urlLower.includes('oaidalleapiprodscus') || urlLower.includes('openai')) {
            return 'DALL-E / ChatGPT';
        }
        if (urlLower.includes('midjourney') || urlLower.includes('mj-')) {
            return 'Midjourney';
        }
        if (urlLower.includes('stability') || urlLower.includes('stablediffusion')) {
            return 'Stable Diffusion';
        }
        if (urlLower.includes('leonardo')) {
            return 'Leonardo.ai';
        }
        if (urlLower.includes('firefly') || urlLower.includes('adobe')) {
            return 'Adobe Firefly';
        }
        if (urlLower.includes('replicate')) {
            return 'Replicate';
        }
        if (urlLower.includes('clipdrop')) {
            return 'ClipDrop';
        }

        return 'AI Image URL';
    }

    function importImage() {
        const input = $('#imageInput').val().trim();
        const title = $('#imageTitle').val() || 'AI Generated Image';
        const alt = $('#imageAlt').val() || '';
        const $status = $('#imageImportStatus');
        const $importBtn = $('#importImage');

        if (!input) {
            $status.html('<span class="dashicons dashicons-warning" style="color:orange;"></span> Please paste an image URL or base64 data first.');
            return;
        }

        $importBtn.prop('disabled', true).html('<span class="dashicons dashicons-update spin"></span> Importing...');
        $status.html('<span class="dashicons dashicons-update spin"></span> Downloading and importing to Media Library...');

        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_import_image',
            nonce: nbAI.nonce,
            input: input,
            title: title,
            alt: alt
        }, function(response) {
            if (response.success) {
                $status.html(`
                    <div class="nb-import-success">
                        <span class="dashicons dashicons-yes-alt" style="color:green;"></span>
                        <strong>Image imported successfully!</strong><br>
                        <a href="${response.data.url}" target="_blank">View Image</a> |
                        <a href="${response.data.edit_url}" target="_blank">Edit in Media Library</a>
                        <br><br>
                        <code>&lt;img src="${response.data.url}" alt="${alt}"&gt;</code>
                    </div>
                `);

                // Clear input for next image
                $('#imageInput').val('');
                $('#imageTitle').val('');
                $('#imageAlt').val('');
                $('#imagePreviewBox').html('<div class="nb-preview-placeholder"><span class="dashicons dashicons-yes" style="color:green;"></span><p>Import complete! Paste another image.</p></div>');

                // Reload recent images list if visible
                if ($('#recentImagesList').length) {
                    setTimeout(() => location.reload(), 1500);
                }
            } else {
                $status.html(`<span class="dashicons dashicons-warning" style="color:red;"></span> ${response.data.message}`);
            }

            $importBtn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Import to Media Library');
        }).fail(function() {
            $status.html('<span class="dashicons dashicons-warning" style="color:red;"></span> Request failed. Please try again.');
            $importBtn.prop('disabled', false).html('<span class="dashicons dashicons-download"></span> Import to Media Library');
        });
    }

    // ========================================
    // History Tab
    // ========================================

    function initHistoryTab() {
        if ($('#historyList').length) {
            loadHistory();
        }
    }

    function loadHistory() {
        $.post(nbAI.ajaxurl, {
            action: 'nb_ai_get_history',
            nonce: nbAI.nonce
        }, function(response) {
            if (response.success) {
                renderHistory(response.data.history);
            }
        });
    }

    function renderHistory(history) {
        const $list = $('#historyList');

        if (!history.length) {
            $list.html('<p class="nb-empty">No exchange history yet.</p>');
            return;
        }

        let html = '<table class="wp-list-table widefat fixed striped"><thead><tr><th>Date</th><th>Shortcode</th><th>Action</th><th>Actions</th></tr></thead><tbody>';

        history.forEach((item, index) => {
            const action = item.action || (item.export ? 'export' : 'import');
            html += `<tr>
                <td>${item.date}</td>
                <td><code>[${item.tag || 'unknown'}]</code></td>
                <td>${action}</td>
                <td>
                    <button type="button" class="button button-small view-history" data-index="${index}">View</button>
                    <button type="button" class="button button-small delete-history" data-index="${index}">Delete</button>
                </td>
            </tr>`;
        });

        html += '</tbody></table>';
        $list.html(html);

        // View handler
        $list.find('.view-history').on('click', function() {
            const idx = $(this).data('index');
            const item = history[idx];
            const content = item.code || item.export || item.import || 'No content';
            $('#modalTitle').text(`History: [${item.tag || 'unknown'}]`);
            $('#modalCode').text(content);
            $('#codeModal').show();
        });

        // Delete handler
        $list.find('.delete-history').on('click', function() {
            const $row = $(this).closest('tr');
            const idx = $(this).data('index');

            $.post(nbAI.ajaxurl, {
                action: 'nb_ai_delete_history',
                nonce: nbAI.nonce,
                index: idx
            }, function(response) {
                if (response.success) {
                    $row.fadeOut(() => loadHistory());
                }
            });
        });
    }

    // ========================================
    // Modal
    // ========================================

    function initModal() {
        $('.nb-modal-close').on('click', () => $('#codeModal').hide());

        $(document).on('click', '#codeModal', function(e) {
            if (e.target === this) $(this).hide();
        });

        $('#modalCopy').on('click', function() {
            const code = $('#modalCode').text();
            navigator.clipboard.writeText(code);
            $(this).html('Copied!');
            setTimeout(() => $(this).html('Copy Code'), 1000);
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') $('#codeModal').hide();
        });
    }

})(jQuery);

// Spin animation
const style = document.createElement('style');
style.textContent = `@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } } .dashicons.spin { animation: spin 1s linear infinite; }`;
document.head.appendChild(style);
