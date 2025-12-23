<?php
/**
 * Plugin Name: NB AI Exchange
 * Plugin URI: https://netbound.ca/plugins/nb-ai-exchange
 * Description: Bridge between WordPress/Divi and AI tools. Export code for AI analysis, paste back AI-generated shortcodes, CSS, Divi code modules, theme builder components, and AI-generated images. Works directly in the post editor!
 * Version: 1.3.1
 * Author: NetBound Tools
 * Author URI: https://netbound.ca
 * License: GPL v2 or later
 * Text Domain: nb-ai-exchange
 *
 * Changelog:
 * v1.3.1 - 2024-12-23
 *   - Added menu icon
 * v1.3.0 - 2024-12-22
 *   - IN-EDITOR AI PANEL! Floating panel appears in post/page editor
 *   - Send selected text, full post, or shortcodes to AI
 *   - Generate optimized image prompts for DALL-E, Midjourney, Stable Diffusion
 *   - Import AI responses and install shortcodes without leaving editor
 *   - Import AI images directly into post
 *   - Classic editor meta box support
 *   - Toggle button in corner of editor
 *
 * v1.2.0 - 2024-12-22
 *   - Added AI Image Import! Paste URL or base64 from DALL-E, Midjourney, etc.
 *   - Images downloaded directly to WordPress Media Library
 *   - Support for PNG, JPG, WEBP, GIF formats
 *   - Base64 image data detection and conversion
 *
 * v1.1.0 - 2024-12-22
 *   - Expanded to support CSS, Divi Code Modules, Theme Builder
 *   - Added Divi-specific prompts (blog styling, headers, footers)
 *   - Multiple code type detection (PHP, CSS, HTML/JS)
 *   - Destination guidance for Customizer, child theme, etc.
 *
 * v1.0.0 - 2024-12-22 - Initial release
 *   - Shortcode browser with nb-* prefix filter
 *   - Export to clipboard with AI prompts
 *   - Import/paste AI responses
 *   - Install new shortcodes from AI code
 *   - Exchange history
 */

if (!defined('ABSPATH')) exit;

class NB_AI_Exchange {

    private static $instance = null;
    const VERSION = '1.3.0';
    const OPTION_HISTORY = 'nb_ai_exchange_history';
    const OPTION_CUSTOM_SHORTCODES = 'nb_ai_exchange_shortcodes';

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_nb_ai_get_shortcodes', [$this, 'ajax_get_shortcodes']);
        add_action('wp_ajax_nb_ai_get_shortcode_source', [$this, 'ajax_get_shortcode_source']);
        add_action('wp_ajax_nb_ai_install_shortcode', [$this, 'ajax_install_shortcode']);
        add_action('wp_ajax_nb_ai_save_exchange', [$this, 'ajax_save_exchange']);
        add_action('wp_ajax_nb_ai_get_history', [$this, 'ajax_get_history']);
        add_action('wp_ajax_nb_ai_delete_history', [$this, 'ajax_delete_history']);
        add_action('wp_ajax_nb_ai_delete_custom_shortcode', [$this, 'ajax_delete_custom_shortcode']);
        add_action('wp_ajax_nb_ai_import_image', [$this, 'ajax_import_image']);

        // Register custom shortcodes on init
        add_action('init', [$this, 'register_custom_shortcodes']);

        // Editor integration - sidebar panel for post/page editing
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
        add_action('add_meta_boxes', [$this, 'add_editor_meta_box']);
        add_action('admin_footer', [$this, 'render_editor_panel']);
    }

    /**
     * Register AI-created shortcodes stored in options
     */
    public function register_custom_shortcodes() {
        $shortcodes = get_option(self::OPTION_CUSTOM_SHORTCODES, []);
        foreach ($shortcodes as $tag => $data) {
            if (!shortcode_exists($tag)) {
                // Create a closure that evaluates the stored code
                add_shortcode($tag, function($atts, $content = null) use ($data) {
                    return $this->execute_custom_shortcode($data['code'], $atts, $content);
                });
            }
        }
    }

    /**
     * Execute a custom shortcode's code safely
     */
    private function execute_custom_shortcode($code, $atts, $content) {
        // The stored code should be a function body that returns HTML
        // We wrap it and execute
        try {
            $func = create_function('$atts, $content', $code);
            if ($func) {
                return $func($atts, $content);
            }
        } catch (Exception $e) {
            if (current_user_can('manage_options')) {
                return '<div class="nb-shortcode-error">Shortcode Error: ' . esc_html($e->getMessage()) . '</div>';
            }
        }
        return '';
    }

    public function add_menu() {
        // Check if NetBound menu exists
        global $menu;
        $netbound_exists = false;
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === 'netbound-tools') {
                $netbound_exists = true;
                break;
            }
        }

        if (!$netbound_exists) {
            add_menu_page(
                'NetBound Tools',
                'NetBound Tools',
                'manage_options',
                'netbound-tools',
                [$this, 'render_page'],
                'dashicons-superhero',
                30
            );
        }

        add_submenu_page(
            'netbound-tools',
            'AI Exchange',
            '🦸 AI Exchange',
            'manage_options',
            'nb-ai-exchange',
            [$this, 'render_page']
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'nb-ai-exchange') === false) return;

        wp_enqueue_style('nb-ai-exchange', plugin_dir_url(__FILE__) . 'assets/style.css', [], self::VERSION);
        wp_enqueue_script('nb-ai-exchange', plugin_dir_url(__FILE__) . 'assets/script.js', ['jquery'], self::VERSION, true);

        // Detect Divi
        $has_divi = defined('ET_BUILDER_VERSION') || wp_get_theme()->get('Name') === 'Divi';
        $divi_version = defined('ET_BUILDER_VERSION') ? ET_BUILDER_VERSION : '';

        wp_localize_script('nb-ai-exchange', 'nbAI', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nb_ai_exchange'),
            'pluginsPath' => WP_PLUGIN_DIR,
            'themePath' => get_stylesheet_directory(),
            'wpVersion' => get_bloginfo('version'),
            'phpVersion' => phpversion(),
            'hasDivi' => $has_divi,
            'diviVersion' => $divi_version,
            'themeName' => wp_get_theme()->get('Name'),
        ]);
    }

    public function render_page() {
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'exchange';
        ?>
        <div class="wrap nb-ai-exchange">
            <h1><span class="dashicons dashicons-superhero-alt"></span> NB AI Exchange</h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=nb-ai-exchange&tab=exchange" class="nav-tab <?php echo $tab === 'exchange' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-randomize"></span> Exchange
                </a>
                <a href="?page=nb-ai-exchange&tab=images" class="nav-tab <?php echo $tab === 'images' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-format-image"></span> AI Images
                </a>
                <a href="?page=nb-ai-exchange&tab=shortcodes" class="nav-tab <?php echo $tab === 'shortcodes' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-shortcode"></span> My Shortcodes
                </a>
                <a href="?page=nb-ai-exchange&tab=history" class="nav-tab <?php echo $tab === 'history' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-backup"></span> History
                </a>
                <a href="?page=nb-ai-exchange&tab=help" class="nav-tab <?php echo $tab === 'help' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-editor-help"></span> Help
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($tab) {
                    case 'images':
                        $this->render_images_tab();
                        break;
                    case 'shortcodes':
                        $this->render_shortcodes_tab();
                        break;
                    case 'history':
                        $this->render_history_tab();
                        break;
                    case 'help':
                        $this->render_help_tab();
                        break;
                    default:
                        $this->render_exchange_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_exchange_tab() {
        ?>
        <div class="nb-exchange-layout">
            <!-- Left Panel: Export to AI -->
            <div class="nb-panel nb-export-panel">
                <div class="nb-panel-header">
                    <h2><span class="dashicons dashicons-upload"></span> Export to AI</h2>
                    <p>Select code to send to your AI assistant</p>
                </div>

                <div class="nb-panel-body">
                    <div class="nb-form-row">
                        <label>Code Type:</label>
                        <select id="exportType">
                            <option value="shortcode">PHP Shortcode</option>
                            <option value="css">CSS Styling</option>
                            <option value="code-module">Divi Code Module</option>
                            <option value="theme-builder">Divi Theme Builder</option>
                            <option value="custom-code">Custom PHP/JS</option>
                        </select>
                    </div>

                    <div class="nb-form-row" id="shortcodeSelectRow">
                        <label>Select Shortcode:</label>
                        <select id="shortcodeSelect">
                            <option value="">-- Loading shortcodes... --</option>
                        </select>
                        <button type="button" id="refreshShortcodes" class="button" title="Refresh list">
                            <span class="dashicons dashicons-update"></span>
                        </button>
                    </div>

                    <div class="nb-form-row" id="pasteCodeRow" style="display:none;">
                        <label>Paste Your Code:</label>
                        <textarea id="pasteCode" rows="6" placeholder="Paste your existing CSS, code module content, or any code you want AI to work with..."></textarea>
                    </div>

                    <div class="nb-form-row">
                        <label>AI Prompt Template:</label>
                        <select id="promptTemplate">
                            <optgroup label="General" id="promptsGeneral">
                                <option value="analyze">Analyze this code</option>
                                <option value="improve">Suggest improvements</option>
                                <option value="explain">Explain how it works</option>
                                <option value="bugs">Find potential bugs</option>
                            </optgroup>
                            <optgroup label="Styling" id="promptsStyling">
                                <option value="style">Improve styling/CSS</option>
                                <option value="responsive">Make responsive</option>
                                <option value="dark-mode">Add dark mode support</option>
                            </optgroup>
                            <optgroup label="Divi Specific" id="promptsDivi">
                                <option value="divi-blog">Style Divi blog module</option>
                                <option value="divi-posts">Style post layouts</option>
                                <option value="divi-header">Create header design</option>
                                <option value="divi-footer">Create footer design</option>
                            </optgroup>
                            <optgroup label="Create New">
                                <option value="create-shortcode">Create new shortcode</option>
                                <option value="create-css">Create new CSS</option>
                                <option value="custom">Custom prompt...</option>
                            </optgroup>
                        </select>
                    </div>

                    <div class="nb-form-row" id="customPromptRow" style="display:none;">
                        <label>Custom Prompt:</label>
                        <textarea id="customPrompt" rows="3" placeholder="Enter your custom instructions for the AI..."></textarea>
                    </div>

                    <div class="nb-form-row nb-checkbox-row">
                        <label>
                            <input type="checkbox" id="includeContext" checked />
                            Include WordPress/Divi context
                        </label>
                        <small>Adds version info and platform basics for AI clarity</small>
                    </div>

                    <div class="nb-form-row">
                        <label>Ready to Copy:</label>
                        <textarea id="exportText" rows="10" readonly placeholder="Select code type above and configure your request..."></textarea>
                    </div>

                    <div class="nb-button-row">
                        <button type="button" id="copyToClipboard" class="button button-primary" disabled>
                            <span class="dashicons dashicons-clipboard"></span> Copy to Clipboard
                        </button>
                        <span id="copyStatus"></span>
                    </div>
                </div>
            </div>

            <!-- Right Panel: Import from AI -->
            <div class="nb-panel nb-import-panel">
                <div class="nb-panel-header">
                    <h2><span class="dashicons dashicons-download"></span> Import from AI</h2>
                    <p>Paste AI response and use the code</p>
                </div>

                <div class="nb-panel-body">
                    <div class="nb-form-row">
                        <label>Paste AI Response:</label>
                        <textarea id="importText" rows="8" placeholder="Paste the AI's response here. The plugin will extract code blocks automatically..."></textarea>
                    </div>

                    <div class="nb-form-row-group">
                        <div class="nb-form-row nb-half">
                            <label>Code Type:</label>
                            <select id="importCodeType">
                                <option value="shortcode">PHP Shortcode (register it)</option>
                                <option value="css">CSS (copy for Customizer/Divi)</option>
                                <option value="code-module">Code Module (HTML/CSS/JS)</option>
                                <option value="theme-builder">Theme Builder JSON</option>
                                <option value="snippet">Reference Only (save to history)</option>
                            </select>
                        </div>

                        <div class="nb-form-row nb-half">
                            <label>Destination:</label>
                            <select id="targetUsage">
                                <option value="any">General WordPress</option>
                                <option value="divi-customizer">Divi → Theme Customizer CSS</option>
                                <option value="divi-code">Divi → Code Module</option>
                                <option value="divi-builder">Divi → Theme Builder</option>
                                <option value="wp-customizer">WP → Additional CSS</option>
                                <option value="child-theme">Child Theme style.css</option>
                            </select>
                        </div>
                    </div>

                    <div id="importTypeHint" class="nb-type-hint">
                        <span class="dashicons dashicons-info"></span>
                        <span id="hintText">This will register a new shortcode in the database.</span>
                    </div>

                    <div class="nb-form-row" id="shortcodeTagRow">
                        <label>Shortcode Tag:</label>
                        <input type="text" id="shortcodeTag" placeholder="e.g., nb-my-shortcode" />
                        <small>The [shortcode] name to register</small>
                    </div>

                    <div class="nb-form-row">
                        <label>Label/Description:</label>
                        <input type="text" id="shortcodeDesc" placeholder="What does this code do? (for your reference)" />
                    </div>

                    <div id="codePreview" style="display:none;">
                        <label>Extracted Code:</label>
                        <pre id="extractedCode"></pre>
                    </div>

                    <div class="nb-button-row">
                        <button type="button" id="parseResponse" class="button">
                            <span class="dashicons dashicons-search"></span> Parse Response
                        </button>
                        <button type="button" id="installShortcode" class="button button-primary" disabled>
                            <span class="dashicons dashicons-yes"></span> Install Shortcode
                        </button>
                        <button type="button" id="copyExtracted" class="button" style="display:none;">
                            <span class="dashicons dashicons-clipboard"></span> Copy Code
                        </button>
                    </div>

                    <div id="installStatus"></div>
                </div>
            </div>
        </div>


        <!-- Quick Tips -->
        <div class="nb-tips-bar">
            <strong>Quick Workflow:</strong>
            1. Select shortcode → 2. Copy to clipboard → 3. Paste in ChatGPT/Claude → 4. Get response → 5. Paste here → 6. Install!
        </div>
        <?php
    }

    private function render_images_tab() {
        ?>
        <div class="nb-panel">
            <div class="nb-panel-header">
                <h2><span class="dashicons dashicons-format-image"></span> Import AI-Generated Images</h2>
                <p>Paste image URLs from DALL-E, Midjourney, Stable Diffusion, ChatGPT, or base64 image data</p>
            </div>

            <div class="nb-panel-body">
                <div class="nb-image-import-layout">
                    <!-- Input Section -->
                    <div class="nb-image-input-section">
                        <div class="nb-form-row">
                            <label>Paste Image URL or Base64 Data:</label>
                            <textarea id="imageInput" rows="4" placeholder="Paste one of these:
• Image URL: https://example.com/image.png
• DALL-E URL: https://oaidalleapiprodscus.blob.core.windows.net/...
• Base64 data: data:image/png;base64,iVBORw0KGgo...
• Or just the base64 string without the prefix"></textarea>
                        </div>

                        <div class="nb-form-row">
                            <label>Image Title (for Media Library):</label>
                            <input type="text" id="imageTitle" placeholder="e.g., AI Generated Hero Image" />
                        </div>

                        <div class="nb-form-row">
                            <label>Alt Text:</label>
                            <input type="text" id="imageAlt" placeholder="Describe the image for accessibility" />
                        </div>

                        <div class="nb-button-row">
                            <button type="button" id="previewImage" class="button">
                                <span class="dashicons dashicons-visibility"></span> Preview
                            </button>
                            <button type="button" id="importImage" class="button button-primary" disabled>
                                <span class="dashicons dashicons-download"></span> Import to Media Library
                            </button>
                        </div>

                        <div id="imageImportStatus"></div>
                    </div>

                    <!-- Preview Section -->
                    <div class="nb-image-preview-section">
                        <div id="imagePreviewBox">
                            <div class="nb-preview-placeholder">
                                <span class="dashicons dashicons-format-image"></span>
                                <p>Image preview will appear here</p>
                            </div>
                        </div>
                        <div id="imageInfo" style="display:none;">
                            <p><strong>Type:</strong> <span id="imageType">-</span></p>
                            <p><strong>Source:</strong> <span id="imageSource">-</span></p>
                        </div>
                    </div>
                </div>

                <!-- Recent Imports -->
                <div class="nb-recent-imports" style="margin-top:30px;">
                    <h3>Recently Imported Images</h3>
                    <div id="recentImagesList">
                        <?php $this->render_recent_images(); ?>
                    </div>
                </div>

                <!-- Tips -->
                <div class="nb-tips-bar" style="margin-top:20px;">
                    <strong>Supported Sources:</strong>
                    ChatGPT/DALL-E • Midjourney • Stable Diffusion • Leonardo.ai • Adobe Firefly • Any direct image URL
                </div>
            </div>
        </div>
        <?php
    }

    private function render_recent_images() {
        // Get last 10 images imported by this plugin
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 10,
            'meta_query' => array(
                array(
                    'key' => '_nb_ai_imported',
                    'value' => '1',
                )
            ),
            'orderby' => 'date',
            'order' => 'DESC',
        );

        $images = get_posts($args);

        if (empty($images)) {
            echo '<p class="nb-empty">No AI images imported yet.</p>';
            return;
        }

        echo '<div class="nb-image-grid">';
        foreach ($images as $image) {
            $thumb = wp_get_attachment_image_src($image->ID, 'thumbnail');
            $full = wp_get_attachment_url($image->ID);
            $source = get_post_meta($image->ID, '_nb_ai_source', true);
            ?>
            <div class="nb-image-grid-item">
                <a href="<?php echo esc_url($full); ?>" target="_blank">
                    <img src="<?php echo esc_url($thumb[0]); ?>" alt="<?php echo esc_attr($image->post_title); ?>">
                </a>
                <div class="nb-image-grid-info">
                    <span class="title"><?php echo esc_html($image->post_title); ?></span>
                    <span class="source"><?php echo esc_html($source ?: 'AI Image'); ?></span>
                </div>
            </div>
            <?php
        }
        echo '</div>';
    }

    private function render_shortcodes_tab() {
        $custom = get_option(self::OPTION_CUSTOM_SHORTCODES, []);
        ?>
        <div class="nb-panel">
            <div class="nb-panel-header">
                <h2><span class="dashicons dashicons-shortcode"></span> AI-Created Shortcodes</h2>
                <p>Shortcodes you've installed via AI Exchange</p>
            </div>

            <div class="nb-panel-body">
                <?php if (empty($custom)): ?>
                    <p class="nb-empty">No custom shortcodes installed yet. Use the Exchange tab to create some!</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Shortcode</th>
                                <th>Description</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($custom as $tag => $data): ?>
                            <tr>
                                <td><code>[<?php echo esc_html($tag); ?>]</code></td>
                                <td><?php echo esc_html($data['description'] ?? 'No description'); ?></td>
                                <td><?php echo esc_html($data['created'] ?? 'Unknown'); ?></td>
                                <td>
                                    <button type="button" class="button button-small view-code" data-tag="<?php echo esc_attr($tag); ?>">
                                        View Code
                                    </button>
                                    <button type="button" class="button button-small delete-shortcode" data-tag="<?php echo esc_attr($tag); ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

                <h3 style="margin-top:30px;">All nb-* Shortcodes on This Site</h3>
                <div id="allShortcodesList">
                    <p><span class="spinner is-active"></span> Loading...</p>
                </div>
            </div>
        </div>

        <!-- Code Viewer Modal -->
        <div id="codeModal" class="nb-modal" style="display:none;">
            <div class="nb-modal-content">
                <span class="nb-modal-close">&times;</span>
                <h3 id="modalTitle">Shortcode Code</h3>
                <pre id="modalCode"></pre>
                <button type="button" class="button" id="modalCopy">Copy Code</button>
            </div>
        </div>
        <?php
    }

    private function render_history_tab() {
        ?>
        <div class="nb-panel">
            <div class="nb-panel-header">
                <h2><span class="dashicons dashicons-backup"></span> Exchange History</h2>
                <p>Your recent AI exchanges for reference</p>
            </div>

            <div class="nb-panel-body">
                <div id="historyList">
                    <p><span class="spinner is-active"></span> Loading history...</p>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_help_tab() {
        ?>
        <div class="nb-panel">
            <div class="nb-panel-header">
                <h2><span class="dashicons dashicons-editor-help"></span> How to Use AI Exchange</h2>
            </div>

            <div class="nb-panel-body nb-help-content">
                <h3>🔄 The Exchange Workflow</h3>
                <ol>
                    <li><strong>Select a Shortcode</strong> - Choose any existing nb-* shortcode from your site</li>
                    <li><strong>Pick a Prompt</strong> - Select what you want the AI to do (analyze, improve, explain, etc.)</li>
                    <li><strong>Copy to Clipboard</strong> - The plugin formats everything nicely for the AI</li>
                    <li><strong>Paste in Your AI Tool</strong> - ChatGPT, Claude, Gemini, or any AI assistant</li>
                    <li><strong>Get the Response</strong> - The AI will provide improved or new code</li>
                    <li><strong>Paste Back Here</strong> - Put the AI's response in the Import panel</li>
                    <li><strong>Install!</strong> - The plugin extracts the PHP code and registers it as a shortcode</li>
                </ol>

                <h3>💡 Tips for Best Results</h3>
                <ul>
                    <li>Use the "Explain" prompt first if you're not sure what a shortcode does</li>
                    <li>The "Improve" prompt is great for cleaning up old code</li>
                    <li>Always test new shortcodes on a staging site first</li>
                    <li>Custom prompts let you ask for specific features</li>
                </ul>

                <h3>⚠️ Important Notes</h3>
                <ul>
                    <li>AI-created shortcodes are stored in the database, not as files</li>
                    <li>They persist through plugin updates</li>
                    <li>Back up your shortcodes using the History tab</li>
                    <li>Only administrators can install shortcodes</li>
                </ul>

                <h3>🎯 Supported AI Tools</h3>
                <p>This plugin works with any web-based AI that accepts text input:</p>
                <ul>
                    <li>ChatGPT (chat.openai.com)</li>
                    <li>Claude (claude.ai)</li>
                    <li>Google Gemini</li>
                    <li>Microsoft Copilot</li>
                    <li>Any other AI assistant</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Get list of nb-* shortcodes
     */
    public function ajax_get_shortcodes() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        global $shortcode_tags;
        $shortcodes = [];

        foreach ($shortcode_tags as $tag => $callback) {
            // Filter to nb-* prefixed shortcodes
            if (strpos($tag, 'nb-') === 0 || strpos($tag, 'nb_') === 0) {
                $source = $this->get_callback_source($callback);
                $shortcodes[] = [
                    'tag' => $tag,
                    'source' => $source['type'],
                    'file' => $source['file'] ?? '',
                ];
            }
        }

        // Sort alphabetically
        usort($shortcodes, function($a, $b) {
            return strcmp($a['tag'], $b['tag']);
        });

        wp_send_json_success(['shortcodes' => $shortcodes]);
    }

    /**
     * Get information about a callback's source
     */
    private function get_callback_source($callback) {
        if (is_string($callback)) {
            // Simple function name
            if (function_exists($callback)) {
                $ref = new ReflectionFunction($callback);
                return [
                    'type' => 'function',
                    'file' => $ref->getFileName(),
                    'start' => $ref->getStartLine(),
                    'end' => $ref->getEndLine(),
                ];
            }
        } elseif (is_array($callback)) {
            // Class method
            $class = is_object($callback[0]) ? get_class($callback[0]) : $callback[0];
            $method = $callback[1];
            if (method_exists($class, $method)) {
                $ref = new ReflectionMethod($class, $method);
                return [
                    'type' => 'method',
                    'class' => $class,
                    'method' => $method,
                    'file' => $ref->getFileName(),
                    'start' => $ref->getStartLine(),
                    'end' => $ref->getEndLine(),
                ];
            }
        } elseif ($callback instanceof Closure) {
            $ref = new ReflectionFunction($callback);
            return [
                'type' => 'closure',
                'file' => $ref->getFileName(),
                'start' => $ref->getStartLine(),
                'end' => $ref->getEndLine(),
            ];
        }

        return ['type' => 'unknown'];
    }

    /**
     * AJAX: Get source code of a shortcode
     */
    public function ajax_get_shortcode_source() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $tag = sanitize_text_field($_POST['tag'] ?? '');
        if (empty($tag)) {
            wp_send_json_error(['message' => 'No shortcode specified']);
        }

        global $shortcode_tags;
        if (!isset($shortcode_tags[$tag])) {
            wp_send_json_error(['message' => 'Shortcode not found']);
        }

        // Check if it's a custom (AI-created) shortcode
        $custom = get_option(self::OPTION_CUSTOM_SHORTCODES, []);
        if (isset($custom[$tag])) {
            wp_send_json_success([
                'tag' => $tag,
                'source' => 'custom',
                'code' => $custom[$tag]['code'],
                'description' => $custom[$tag]['description'] ?? '',
            ]);
        }

        // Get source from file
        $callback = $shortcode_tags[$tag];
        $source = $this->get_callback_source($callback);

        if (isset($source['file']) && file_exists($source['file'])) {
            $lines = file($source['file']);
            $code = '';

            // Get a bit of context before and the function itself
            $start = max(0, $source['start'] - 5);
            $end = min(count($lines), $source['end'] + 2);

            for ($i = $start; $i < $end; $i++) {
                $code .= $lines[$i];
            }

            wp_send_json_success([
                'tag' => $tag,
                'source' => $source['type'],
                'file' => str_replace(ABSPATH, '', $source['file']),
                'code' => $code,
                'lines' => ($source['start']) . '-' . $source['end'],
            ]);
        }

        wp_send_json_error(['message' => 'Could not retrieve source code']);
    }

    /**
     * AJAX: Install a new shortcode from AI code
     */
    public function ajax_install_shortcode() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $tag = sanitize_key($_POST['tag'] ?? '');
        $code = wp_unslash($_POST['code'] ?? '');
        $description = sanitize_text_field($_POST['description'] ?? '');
        $is_update = !empty($_POST['isUpdate']);
        $target = sanitize_key($_POST['target'] ?? 'any');

        if (empty($tag)) {
            wp_send_json_error(['message' => 'Shortcode tag is required']);
        }

        if (empty($code)) {
            wp_send_json_error(['message' => 'No code provided']);
        }

        // Basic validation - check for dangerous functions
        $dangerous = ['eval', 'exec', 'system', 'shell_exec', 'passthru', 'popen', 'proc_open'];
        foreach ($dangerous as $func) {
            if (stripos($code, $func) !== false) {
                wp_send_json_error(['message' => "Code contains potentially dangerous function: $func"]);
            }
        }

        // Store the shortcode
        $custom = get_option(self::OPTION_CUSTOM_SHORTCODES, []);
        $existing = isset($custom[$tag]);

        $custom[$tag] = [
            'code' => $code,
            'description' => $description,
            'target' => $target,
            'created' => $existing ? ($custom[$tag]['created'] ?? current_time('Y-m-d H:i')) : current_time('Y-m-d H:i'),
            'updated' => current_time('Y-m-d H:i'),
        ];
        update_option(self::OPTION_CUSTOM_SHORTCODES, $custom);

        // Save to history
        $action = $is_update ? 'update' : 'install';
        $this->save_to_history($tag, $code, $action);

        $verb = $is_update ? 'updated' : 'installed';
        wp_send_json_success([
            'message' => "Shortcode [$tag] $verb successfully!",
            'tag' => $tag,
            'isUpdate' => $is_update,
        ]);
    }

    /**
     * AJAX: Save an exchange to history
     */
    public function ajax_save_exchange() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $export = wp_unslash($_POST['export'] ?? '');
        $import = wp_unslash($_POST['import'] ?? '');
        $tag = sanitize_text_field($_POST['tag'] ?? '');

        $history = get_option(self::OPTION_HISTORY, []);

        array_unshift($history, [
            'tag' => $tag,
            'export' => $export,
            'import' => $import,
            'date' => current_time('Y-m-d H:i:s'),
        ]);

        // Keep only last 50 entries
        $history = array_slice($history, 0, 50);

        update_option(self::OPTION_HISTORY, $history);

        wp_send_json_success(['message' => 'Saved to history']);
    }

    private function save_to_history($tag, $code, $action) {
        $history = get_option(self::OPTION_HISTORY, []);

        array_unshift($history, [
            'tag' => $tag,
            'action' => $action,
            'code' => $code,
            'date' => current_time('Y-m-d H:i:s'),
        ]);

        $history = array_slice($history, 0, 50);
        update_option(self::OPTION_HISTORY, $history);
    }

    /**
     * AJAX: Get history
     */
    public function ajax_get_history() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $history = get_option(self::OPTION_HISTORY, []);
        wp_send_json_success(['history' => $history]);
    }

    /**
     * AJAX: Delete history entry
     */
    public function ajax_delete_history() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $index = intval($_POST['index'] ?? -1);
        $history = get_option(self::OPTION_HISTORY, []);

        if (isset($history[$index])) {
            array_splice($history, $index, 1);
            update_option(self::OPTION_HISTORY, $history);
            wp_send_json_success(['message' => 'Deleted']);
        }

        wp_send_json_error(['message' => 'Entry not found']);
    }

    /**
     * AJAX: Delete custom shortcode
     */
    public function ajax_delete_custom_shortcode() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $tag = sanitize_key($_POST['tag'] ?? '');
        $custom = get_option(self::OPTION_CUSTOM_SHORTCODES, []);

        if (isset($custom[$tag])) {
            unset($custom[$tag]);
            update_option(self::OPTION_CUSTOM_SHORTCODES, $custom);
            wp_send_json_success(['message' => "Shortcode [$tag] deleted"]);
        }

        wp_send_json_error(['message' => 'Shortcode not found']);
    }

    /**
     * AJAX: Import AI-generated image to Media Library
     */
    public function ajax_import_image() {
        check_ajax_referer('nb_ai_exchange', 'nonce');

        if (!current_user_can('upload_files')) {
            wp_send_json_error(['message' => 'Permission denied - you cannot upload files']);
        }

        $input = wp_unslash($_POST['input'] ?? '');
        $title = sanitize_text_field($_POST['title'] ?? 'AI Generated Image');
        $alt = sanitize_text_field($_POST['alt'] ?? '');

        if (empty($input)) {
            wp_send_json_error(['message' => 'No image URL or data provided']);
        }

        // Determine input type
        $image_data = null;
        $filename = '';
        $source = 'AI Image';

        // Check if it's base64 data
        if (preg_match('/^data:image\/(\w+);base64,(.+)$/i', $input, $matches)) {
            // Full data URI
            $extension = strtolower($matches[1]);
            $image_data = base64_decode($matches[2]);
            $filename = 'ai-image-' . time() . '.' . $extension;
            $source = 'Base64 Data';
        } elseif (preg_match('/^[a-zA-Z0-9+\/=]+$/s', trim($input)) && strlen($input) > 100) {
            // Raw base64 string (no prefix) - assume PNG
            $image_data = base64_decode(trim($input));
            if ($image_data === false) {
                wp_send_json_error(['message' => 'Invalid base64 data']);
            }
            // Try to detect actual image type from magic bytes
            $extension = $this->detect_image_type($image_data);
            $filename = 'ai-image-' . time() . '.' . $extension;
            $source = 'Base64 Data';
        } elseif (filter_var($input, FILTER_VALIDATE_URL)) {
            // It's a URL - download it
            $source = $this->detect_ai_source($input);

            $response = wp_remote_get($input, [
                'timeout' => 30,
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);

            if (is_wp_error($response)) {
                wp_send_json_error(['message' => 'Failed to download image: ' . $response->get_error_message()]);
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                wp_send_json_error(['message' => 'Failed to download image: HTTP ' . $response_code]);
            }

            $image_data = wp_remote_retrieve_body($response);
            $content_type = wp_remote_retrieve_header($response, 'content-type');

            // Get extension from content type or URL
            $extension = $this->get_extension_from_content_type($content_type);
            if (!$extension) {
                $extension = pathinfo(parse_url($input, PHP_URL_PATH), PATHINFO_EXTENSION);
            }
            if (!$extension || !in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $extension = $this->detect_image_type($image_data);
            }

            $filename = 'ai-image-' . time() . '.' . $extension;
        } else {
            wp_send_json_error(['message' => 'Invalid input - please provide a URL or base64 image data']);
        }

        if (empty($image_data)) {
            wp_send_json_error(['message' => 'Could not retrieve image data']);
        }

        // Validate it's actually an image
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($image_data);
        if (strpos($mime, 'image/') !== 0) {
            wp_send_json_error(['message' => 'The data is not a valid image (detected: ' . $mime . ')']);
        }

        // Upload to WordPress
        $upload = wp_upload_bits($filename, null, $image_data);

        if ($upload['error']) {
            wp_send_json_error(['message' => 'Upload failed: ' . $upload['error']]);
        }

        // Create attachment
        $attachment = [
            'post_mime_type' => $mime,
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $upload['file']);

        if (is_wp_error($attach_id)) {
            wp_send_json_error(['message' => 'Failed to create attachment']);
        }

        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        // Set alt text
        if ($alt) {
            update_post_meta($attach_id, '_wp_attachment_image_alt', $alt);
        }

        // Mark as AI-imported
        update_post_meta($attach_id, '_nb_ai_imported', '1');
        update_post_meta($attach_id, '_nb_ai_source', $source);
        update_post_meta($attach_id, '_nb_ai_date', current_time('mysql'));

        // Save to history
        $this->save_to_history('image:' . $title, $input, 'import-image');

        wp_send_json_success([
            'message' => 'Image imported successfully!',
            'attachment_id' => $attach_id,
            'url' => wp_get_attachment_url($attach_id),
            'thumbnail' => wp_get_attachment_image_src($attach_id, 'thumbnail')[0] ?? '',
            'edit_url' => admin_url('post.php?post=' . $attach_id . '&action=edit'),
        ]);
    }

    /**
     * Detect AI source from URL
     */
    private function detect_ai_source($url) {
        $url_lower = strtolower($url);

        if (strpos($url_lower, 'oaidalleapiprodscus') !== false || strpos($url_lower, 'openai') !== false) {
            return 'DALL-E / ChatGPT';
        }
        if (strpos($url_lower, 'midjourney') !== false || strpos($url_lower, 'mj-') !== false) {
            return 'Midjourney';
        }
        if (strpos($url_lower, 'stability') !== false || strpos($url_lower, 'stablediffusion') !== false) {
            return 'Stable Diffusion';
        }
        if (strpos($url_lower, 'leonardo') !== false) {
            return 'Leonardo.ai';
        }
        if (strpos($url_lower, 'firefly') !== false || strpos($url_lower, 'adobe') !== false) {
            return 'Adobe Firefly';
        }
        if (strpos($url_lower, 'replicate') !== false) {
            return 'Replicate';
        }

        return 'AI Image URL';
    }

    /**
     * Detect image type from binary data
     */
    private function detect_image_type($data) {
        $signatures = [
            'png' => "\x89PNG",
            'jpg' => "\xFF\xD8\xFF",
            'gif' => "GIF",
            'webp' => "RIFF",
        ];

        foreach ($signatures as $type => $sig) {
            if (strpos($data, $sig) === 0) {
                return $type;
            }
        }

        return 'png'; // Default fallback
    }

    /**
     * Get file extension from content-type header
     */
    private function get_extension_from_content_type($content_type) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];

        $content_type = strtolower(trim(explode(';', $content_type)[0]));
        return $map[$content_type] ?? null;
    }

    // ========================================
    // EDITOR INTEGRATION - v1.3.0
    // ========================================

    /**
     * Enqueue assets for the block editor (Gutenberg)
     */
    public function enqueue_editor_assets() {
        global $pagenow;

        // Only on post/page edit screens
        if (!in_array($pagenow, ['post.php', 'post-new.php'])) {
            return;
        }

        wp_enqueue_style(
            'nb-ai-exchange-editor',
            plugin_dir_url(__FILE__) . 'assets/editor.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'nb-ai-exchange-editor',
            plugin_dir_url(__FILE__) . 'assets/editor.js',
            ['jquery', 'wp-data', 'wp-editor'],
            self::VERSION,
            true
        );

        // Detect Divi
        $has_divi = defined('ET_BUILDER_VERSION') || wp_get_theme()->get('Name') === 'Divi';

        wp_localize_script('nb-ai-exchange-editor', 'nbAIEditor', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nb_ai_exchange'),
            'adminUrl' => admin_url('admin.php?page=nb-ai-exchange'),
            'hasDivi' => $has_divi,
        ]);
    }

    /**
     * Add meta box for classic editor
     */
    public function add_editor_meta_box() {
        add_meta_box(
            'nb-ai-exchange-box',
            '🤖 AI Exchange',
            [$this, 'render_meta_box'],
            ['post', 'page'],
            'side',
            'high'
        );
    }

    /**
     * Render the classic editor meta box
     */
    public function render_meta_box($post) {
        ?>
        <div class="nb-editor-mini">
            <p class="nb-editor-desc">Send content to AI or import AI responses</p>

            <button type="button" class="button nb-editor-btn" id="nbEditorSendSelection">
                <span class="dashicons dashicons-upload"></span> Send Selected Text
            </button>

            <button type="button" class="button nb-editor-btn" id="nbEditorImagePrompt">
                <span class="dashicons dashicons-format-image"></span> Describe Image Need
            </button>

            <button type="button" class="button nb-editor-btn" id="nbEditorPasteCode">
                <span class="dashicons dashicons-shortcode"></span> Paste Shortcode
            </button>

            <div style="margin-top: 10px;">
                <a href="<?php echo admin_url('admin.php?page=nb-ai-exchange'); ?>" target="_blank" class="button button-link">
                    Open Full AI Exchange →
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Render the floating editor panel (appears on post edit screens)
     */
    public function render_editor_panel() {
        global $pagenow;

        // Only on post/page edit screens
        if (!in_array($pagenow, ['post.php', 'post-new.php'])) {
            return;
        }
        ?>
        <!-- NB AI Exchange Editor Panel v1.3.0 -->
        <div id="nb-ai-editor-panel" class="nb-ai-editor-panel" style="display:none;">
            <div class="nb-ai-editor-header">
                <span class="dashicons dashicons-superhero-alt"></span>
                <strong>AI Exchange</strong>
                <button type="button" class="nb-ai-close" title="Close">&times;</button>
            </div>

            <div class="nb-ai-editor-tabs">
                <button type="button" class="nb-ai-tab active" data-tab="send">Send to AI</button>
                <button type="button" class="nb-ai-tab" data-tab="import">Import</button>
                <button type="button" class="nb-ai-tab" data-tab="image">Image</button>
            </div>

            <!-- Send to AI Tab -->
            <div class="nb-ai-editor-content" data-content="send">
                <div class="nb-ai-form-row">
                    <label>What to send:</label>
                    <select id="nbAISendType">
                        <option value="selection">Selected text</option>
                        <option value="post">Full post content</option>
                        <option value="shortcode">A shortcode</option>
                        <option value="custom">Custom text</option>
                    </select>
                </div>

                <div class="nb-ai-form-row" id="nbAICustomTextRow" style="display:none;">
                    <textarea id="nbAICustomText" rows="3" placeholder="Paste or type what you want to send..."></textarea>
                </div>

                <div class="nb-ai-form-row">
                    <label>Request:</label>
                    <select id="nbAIRequest">
                        <option value="explain">Explain this code/shortcode</option>
                        <option value="improve">Suggest improvements</option>
                        <option value="fix">Fix issues</option>
                        <option value="convert">Convert to shortcode</option>
                        <option value="describe-image">Describe for AI image</option>
                        <option value="custom">Custom prompt...</option>
                    </select>
                </div>

                <div class="nb-ai-form-row" id="nbAICustomPromptRow" style="display:none;">
                    <textarea id="nbAICustomPrompt" rows="2" placeholder="What do you want the AI to do?"></textarea>
                </div>

                <div class="nb-ai-form-row">
                    <label>Ready to copy:</label>
                    <textarea id="nbAIOutput" rows="6" readonly placeholder="Click 'Prepare' to generate..."></textarea>
                </div>

                <div class="nb-ai-button-row">
                    <button type="button" class="button" id="nbAIPrepare">
                        <span class="dashicons dashicons-edit"></span> Prepare
                    </button>
                    <button type="button" class="button button-primary" id="nbAICopy" disabled>
                        <span class="dashicons dashicons-clipboard"></span> Copy
                    </button>
                </div>
            </div>

            <!-- Import Tab -->
            <div class="nb-ai-editor-content" data-content="import" style="display:none;">
                <div class="nb-ai-form-row">
                    <label>Paste AI response:</label>
                    <textarea id="nbAIImportText" rows="5" placeholder="Paste the AI's code response..."></textarea>
                </div>

                <div class="nb-ai-form-row">
                    <label>Code type:</label>
                    <select id="nbAIImportType">
                        <option value="shortcode">PHP Shortcode</option>
                        <option value="html">HTML/Block</option>
                        <option value="css">CSS</option>
                    </select>
                </div>

                <div class="nb-ai-form-row" id="nbAITagRow">
                    <label>Shortcode name:</label>
                    <input type="text" id="nbAIShortcodeTag" placeholder="nb-my-shortcode" />
                </div>

                <div class="nb-ai-button-row">
                    <button type="button" class="button" id="nbAIParse">
                        <span class="dashicons dashicons-search"></span> Parse
                    </button>
                    <button type="button" class="button" id="nbAIInsertEditor" disabled>
                        <span class="dashicons dashicons-editor-paste-text"></span> Insert
                    </button>
                    <button type="button" class="button button-primary" id="nbAIInstall" disabled>
                        <span class="dashicons dashicons-yes"></span> Install
                    </button>
                </div>

                <div id="nbAIImportStatus"></div>
            </div>

            <!-- Image Tab -->
            <div class="nb-ai-editor-content" data-content="image" style="display:none;">
                <div class="nb-ai-form-row">
                    <label>Describe what you need:</label>
                    <textarea id="nbAIImageDesc" rows="3" placeholder="e.g., A hero image showing a cozy coffee shop with warm lighting..."></textarea>
                </div>

                <div class="nb-ai-form-row">
                    <label>AI Tool:</label>
                    <select id="nbAIImageTool">
                        <option value="dalle">DALL-E / ChatGPT</option>
                        <option value="midjourney">Midjourney</option>
                        <option value="stable">Stable Diffusion</option>
                        <option value="leonardo">Leonardo.ai</option>
                        <option value="ideogram">Ideogram</option>
                    </select>
                </div>

                <div class="nb-ai-form-row">
                    <label>Optimized prompt:</label>
                    <textarea id="nbAIImagePrompt" rows="4" readonly placeholder="Click 'Generate Prompt' first..."></textarea>
                </div>

                <div class="nb-ai-button-row">
                    <button type="button" class="button" id="nbAIGenPrompt">
                        <span class="dashicons dashicons-edit"></span> Generate Prompt
                    </button>
                    <button type="button" class="button button-primary" id="nbAICopyPrompt" disabled>
                        <span class="dashicons dashicons-clipboard"></span> Copy
                    </button>
                </div>

                <hr style="margin: 15px 0;">

                <div class="nb-ai-form-row">
                    <label>Paste image URL after generating:</label>
                    <input type="text" id="nbAIImageUrl" placeholder="https://..." />
                </div>

                <div class="nb-ai-button-row">
                    <button type="button" class="button button-primary" id="nbAIImportImage" disabled>
                        <span class="dashicons dashicons-download"></span> Import & Insert
                    </button>
                </div>

                <div id="nbAIImageStatus"></div>
            </div>

            <div class="nb-ai-editor-footer">
                <a href="<?php echo admin_url('admin.php?page=nb-ai-exchange'); ?>" target="_blank">
                    Open Full AI Exchange →
                </a>
            </div>
        </div>

        <!-- Toggle Button -->
        <button type="button" id="nb-ai-editor-toggle" class="nb-ai-editor-toggle" title="AI Exchange">
            <span class="dashicons dashicons-superhero-alt"></span>
        </button>
        <?php
    }
}

// Initialize
NB_AI_Exchange::get_instance();
