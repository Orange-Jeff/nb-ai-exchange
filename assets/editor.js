/**
 * NB AI Exchange - Editor Integration
 * Version 1.3.0
 *
 * Provides in-editor AI tools for post/page editing
 * - Send text/shortcodes to AI with prompts
 * - Import AI responses (code, shortcodes)
 * - Generate image prompts and import AI images
 */
(function($) {
    'use strict';

    let extractedCode = '';
    let extractedType = '';

    $(document).ready(function() {
        initEditorPanel();
        initTabs();
        initSendTab();
        initImportTab();
        initImageTab();
        initMetaBox();
    });

    // ========================================
    // Panel Toggle & Tabs
    // ========================================

    function initEditorPanel() {
        const $toggle = $('#nb-ai-editor-toggle');
        const $panel = $('#nb-ai-editor-panel');

        // Toggle panel visibility
        $toggle.on('click', function() {
            $panel.toggle();
            $toggle.toggleClass('active', $panel.is(':visible'));
        });

        // Close button
        $panel.find('.nb-ai-close').on('click', function() {
            $panel.hide();
            $toggle.removeClass('active');
        });

        // Make panel draggable if jQuery UI is available
        if ($.fn.draggable) {
            $panel.draggable({
                handle: '.nb-ai-editor-header',
                containment: 'window'
            });
        }
    }

    function initTabs() {
        $('.nb-ai-tab').on('click', function() {
            const tab = $(this).data('tab');

            // Update tab buttons
            $('.nb-ai-tab').removeClass('active');
            $(this).addClass('active');

            // Show corresponding content
            $('.nb-ai-editor-content').hide();
            $(`.nb-ai-editor-content[data-content="${tab}"]`).show();
        });
    }

    // ========================================
    // Send to AI Tab
    // ========================================

    function initSendTab() {
        // Toggle custom text field
        $('#nbAISendType').on('change', function() {
            const val = $(this).val();
            $('#nbAICustomTextRow').toggle(val === 'custom' || val === 'shortcode');

            if (val === 'shortcode') {
                $('#nbAICustomText').attr('placeholder', 'Paste the [shortcode] you want help with...');
            } else {
                $('#nbAICustomText').attr('placeholder', 'Paste or type what you want to send...');
            }
        });

        // Toggle custom prompt field
        $('#nbAIRequest').on('change', function() {
            $('#nbAICustomPromptRow').toggle($(this).val() === 'custom');
        });

        // Prepare button
        $('#nbAIPrepare').on('click', function() {
            const sendType = $('#nbAISendType').val();
            const request = $('#nbAIRequest').val();
            let content = '';

            // Get content based on selection
            switch (sendType) {
                case 'selection':
                    content = getSelectedText();
                    if (!content) {
                        alert('Please select some text in the editor first.');
                        return;
                    }
                    break;
                case 'post':
                    content = getPostContent();
                    break;
                case 'shortcode':
                case 'custom':
                    content = $('#nbAICustomText').val().trim();
                    if (!content) {
                        alert('Please enter some text or a shortcode.');
                        return;
                    }
                    break;
            }

            // Build the prompt
            const prompt = buildPrompt(content, request);
            $('#nbAIOutput').val(prompt);
            $('#nbAICopy').prop('disabled', false);
        });

        // Copy button
        $('#nbAICopy').on('click', function() {
            const text = $('#nbAIOutput').val();
            copyToClipboard(text);
            showStatus($(this), 'Copied!');
        });
    }

    function getSelectedText() {
        // Try Gutenberg first
        if (typeof wp !== 'undefined' && wp.data) {
            const selection = window.getSelection();
            if (selection && selection.toString().trim()) {
                return selection.toString().trim();
            }
        }

        // Try classic editor (TinyMCE)
        if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
            const selection = tinyMCE.activeEditor.selection.getContent({ format: 'text' });
            if (selection) return selection;
        }

        // Fallback to window selection
        return window.getSelection().toString().trim();
    }

    function getPostContent() {
        // Gutenberg
        if (typeof wp !== 'undefined' && wp.data) {
            const content = wp.data.select('core/editor').getEditedPostContent();
            if (content) return content;
        }

        // Classic editor
        if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
            return tinyMCE.activeEditor.getContent({ format: 'text' });
        }

        // Text mode
        const $textarea = $('#content');
        if ($textarea.length) {
            return $textarea.val();
        }

        return '';
    }

    function buildPrompt(content, requestType) {
        const prompts = {
            'explain': `Please explain what this code does:\n\n\`\`\`\n${content}\n\`\`\`\n\nExplain its purpose, how it works, and any important details.`,

            'improve': `Please review and suggest improvements for this code:\n\n\`\`\`\n${content}\n\`\`\`\n\nSuggest optimizations, better practices, and any fixes needed.`,

            'fix': `Please fix any issues in this code:\n\n\`\`\`\n${content}\n\`\`\`\n\nIdentify bugs, errors, or problems and provide corrected code.`,

            'convert': `Please convert this content into a WordPress shortcode:\n\n\`\`\`\n${content}\n\`\`\`\n\nCreate a properly structured PHP shortcode function with:\n- Appropriate attributes\n- Proper escaping and security\n- Clean, maintainable code\n- Return the complete shortcode code ready to install.`,

            'describe-image': `Based on this content, suggest an AI image prompt:\n\n"${content}"\n\nCreate a detailed image generation prompt that would work well for DALL-E or Midjourney. Include:\n- Visual style\n- Mood/atmosphere\n- Key elements\n- Composition suggestions`,

            'custom': $('#nbAICustomPrompt').val() + `\n\n\`\`\`\n${content}\n\`\`\``
        };

        let prompt = prompts[requestType] || prompts['explain'];

        // Add context
        if (nbAIEditor.hasDivi) {
            prompt += '\n\nContext: This is for a WordPress site using the Divi theme.';
        } else {
            prompt += '\n\nContext: This is for a WordPress site.';
        }

        return prompt;
    }

    // ========================================
    // Import Tab
    // ========================================

    function initImportTab() {
        // Toggle shortcode tag field
        $('#nbAIImportType').on('change', function() {
            $('#nbAITagRow').toggle($(this).val() === 'shortcode');
        });

        // Parse button
        $('#nbAIParse').on('click', function() {
            const text = $('#nbAIImportText').val();
            const type = $('#nbAIImportType').val();

            if (!text.trim()) {
                showStatus($('#nbAIImportStatus'), 'Please paste some AI response first.', 'error');
                return;
            }

            extractedCode = extractCode(text, type);
            extractedType = type;

            if (extractedCode) {
                showStatus($('#nbAIImportStatus'), 'Code extracted! Choose an action.', 'success');
                $('#nbAIInsertEditor').prop('disabled', false);
                $('#nbAIInstall').prop('disabled', type !== 'shortcode');
            } else {
                showStatus($('#nbAIImportStatus'), 'Could not find code blocks. Try pasting just the code.', 'error');
            }
        });

        // Insert into editor button
        $('#nbAIInsertEditor').on('click', function() {
            if (!extractedCode) return;

            if (extractedType === 'shortcode') {
                // For shortcodes, insert the shortcode tag
                const tag = $('#nbAIShortcodeTag').val().trim() || 'nb-custom';
                insertIntoEditor(`[${tag}]`);
            } else if (extractedType === 'html') {
                // Insert HTML directly
                insertIntoEditor(extractedCode);
            } else if (extractedType === 'css') {
                // CSS goes into a style block
                insertIntoEditor(`<style>\n${extractedCode}\n</style>`);
            }

            showStatus($('#nbAIImportStatus'), 'Inserted into editor!', 'success');
        });

        // Install shortcode button
        $('#nbAIInstall').on('click', function() {
            if (!extractedCode || extractedType !== 'shortcode') return;

            const tag = $('#nbAIShortcodeTag').val().trim();
            if (!tag) {
                showStatus($('#nbAIImportStatus'), 'Please enter a shortcode name.', 'error');
                return;
            }

            // Send to server to install
            $.post(nbAIEditor.ajaxurl, {
                action: 'nb_ai_install_shortcode',
                nonce: nbAIEditor.nonce,
                tag: tag,
                code: extractedCode,
                description: 'Installed from editor panel'
            }, function(response) {
                if (response.success) {
                    showStatus($('#nbAIImportStatus'), `Shortcode [${tag}] installed! You can now use it.`, 'success');
                    // Insert into editor
                    insertIntoEditor(`[${tag}]`);
                } else {
                    showStatus($('#nbAIImportStatus'), 'Error: ' + response.data.message, 'error');
                }
            });
        });
    }

    function extractCode(text, type) {
        // Look for code blocks with language hints
        let regex;
        switch (type) {
            case 'shortcode':
                regex = /```(?:php)?\n?([\s\S]*?)```/gi;
                break;
            case 'html':
                regex = /```(?:html)?\n?([\s\S]*?)```/gi;
                break;
            case 'css':
                regex = /```(?:css)?\n?([\s\S]*?)```/gi;
                break;
            default:
                regex = /```[\w]*\n?([\s\S]*?)```/gi;
        }

        const matches = [...text.matchAll(regex)];
        if (matches.length > 0) {
            // Return all code blocks combined
            return matches.map(m => m[1].trim()).join('\n\n');
        }

        // No code blocks found, try to detect if the whole thing is code
        if (type === 'shortcode' && (text.includes('function') || text.includes('add_shortcode'))) {
            return text.trim();
        }
        if (type === 'css' && (text.includes('{') && text.includes('}'))) {
            return text.trim();
        }
        if (type === 'html' && text.includes('<')) {
            return text.trim();
        }

        return null;
    }

    function insertIntoEditor(content) {
        // Try Gutenberg
        if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
            const block = wp.blocks.createBlock('core/html', { content: content });
            wp.data.dispatch('core/block-editor').insertBlocks(block);
            return;
        }

        // Try classic editor (TinyMCE)
        if (typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor) {
            tinyMCE.activeEditor.execCommand('mceInsertContent', false, content);
            return;
        }

        // Text mode fallback
        const $textarea = $('#content');
        if ($textarea.length) {
            const cursorPos = $textarea[0].selectionStart;
            const val = $textarea.val();
            $textarea.val(val.substring(0, cursorPos) + content + val.substring(cursorPos));
        }
    }

    // ========================================
    // Image Tab
    // ========================================

    function initImageTab() {
        // Generate prompt button
        $('#nbAIGenPrompt').on('click', function() {
            const description = $('#nbAIImageDesc').val().trim();
            const tool = $('#nbAIImageTool').val();

            if (!description) {
                alert('Please describe what image you need.');
                return;
            }

            const prompt = generateImagePrompt(description, tool);
            $('#nbAIImagePrompt').val(prompt);
            $('#nbAICopyPrompt').prop('disabled', false);
        });

        // Copy prompt button
        $('#nbAICopyPrompt').on('click', function() {
            copyToClipboard($('#nbAIImagePrompt').val());
            showStatus($(this), 'Copied!');
        });

        // Enable import when URL entered
        $('#nbAIImageUrl').on('input', function() {
            $('#nbAIImportImage').prop('disabled', !$(this).val().trim());
        });

        // Import image button
        $('#nbAIImportImage').on('click', function() {
            const url = $('#nbAIImageUrl').val().trim();
            if (!url) return;

            const $status = $('#nbAIImageStatus');
            $status.html('<span class="dashicons dashicons-update spin"></span> Importing...');

            $.post(nbAIEditor.ajaxurl, {
                action: 'nb_ai_import_image',
                nonce: nbAIEditor.nonce,
                image_data: url,
                title: 'AI Generated Image',
                alt: $('#nbAIImageDesc').val().trim() || ''
            }, function(response) {
                if (response.success) {
                    showStatus($status, 'Image imported!', 'success');

                    // Insert image into editor
                    const imgHtml = `<img src="${response.data.url}" alt="${response.data.alt || ''}" class="aligncenter" />`;
                    insertIntoEditor(imgHtml);

                    // Clear the URL field
                    $('#nbAIImageUrl').val('');
                    $('#nbAIImportImage').prop('disabled', true);
                } else {
                    showStatus($status, 'Error: ' + (response.data.message || 'Import failed'), 'error');
                }
            }).fail(function() {
                showStatus($status, 'Network error. Please try again.', 'error');
            });
        });
    }

    function generateImagePrompt(description, tool) {
        const prompts = {
            'dalle': `Create a photorealistic image: ${description}\n\nStyle: High quality, detailed, professional photography style. Good lighting and composition.`,

            'midjourney': `${description} --style raw --ar 16:9 --q 2\n\nNote: Adjust aspect ratio (--ar) and quality (--q) as needed. Use --v 6 for latest version.`,

            'stable': `${description}\n\nPositive: highly detailed, professional quality, sharp focus, beautiful lighting\nNegative: blurry, low quality, distorted, watermark`,

            'leonardo': `${description}\n\nRecommended: Use PhotoReal or DreamShaper model for best results. Enable Alchemy for enhanced quality.`,

            'ideogram': `${description}\n\nTip: Ideogram excels at text in images. Specify exact text you want rendered if applicable.`
        };

        return prompts[tool] || prompts['dalle'];
    }

    // ========================================
    // Classic Editor Meta Box
    // ========================================

    function initMetaBox() {
        // Send selection button (in meta box)
        $('#nbEditorSendSelection').on('click', function() {
            $('#nb-ai-editor-panel').show();
            $('#nb-ai-editor-toggle').addClass('active');
            $('.nb-ai-tab[data-tab="send"]').click();
            $('#nbAISendType').val('selection').trigger('change');
        });

        // Image prompt button
        $('#nbEditorImagePrompt').on('click', function() {
            $('#nb-ai-editor-panel').show();
            $('#nb-ai-editor-toggle').addClass('active');
            $('.nb-ai-tab[data-tab="image"]').click();
        });

        // Paste shortcode button
        $('#nbEditorPasteCode').on('click', function() {
            $('#nb-ai-editor-panel').show();
            $('#nb-ai-editor-toggle').addClass('active');
            $('.nb-ai-tab[data-tab="import"]').click();
        });
    }

    // ========================================
    // Utilities
    // ========================================

    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).catch(function() {
            // Fallback
            const $temp = $('<textarea>').val(text).appendTo('body').select();
            document.execCommand('copy');
            $temp.remove();
        });
    }

    function showStatus($element, message, type) {
        type = type || 'info';
        const icon = type === 'success' ? 'yes' : (type === 'error' ? 'no' : 'info');

        if ($element.is('button')) {
            const originalText = $element.html();
            $element.html(`<span class="dashicons dashicons-${icon}"></span> ${message}`);
            setTimeout(() => $element.html(originalText), 2000);
        } else {
            $element.html(`<span class="nb-status nb-status-${type}"><span class="dashicons dashicons-${icon}"></span> ${message}</span>`);
        }
    }

})(jQuery);
