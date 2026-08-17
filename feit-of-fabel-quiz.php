<?php
/**
 * Plugin Name: Feit of Fabel-Quiz
 * Description: Create ACF-managed Feit of Fabel-quizzes.
 * Version: 1.4.6
 * Update URI: https://github.com/daniellinski/fof-wp
 * Author: Daniël Dols, Trimbos Instituut
 * Text Domain: feit-of-fabel-quiz
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

defined('ABSPATH') || exit;

require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$fof_quiz_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/daniellinski/fof-wp',
    __FILE__,
    'feit-of-fabel-quiz'
);
$fof_quiz_update_checker->setBranch('main');

final class FOF_Quiz_Plugin {
    const VERSION = '1.4.6';
    const POST_TYPE = 'fof_quiz';
    const SHORTCODE = 'feit_of_fabel_quiz';
    const BLOCK_NAME = 'feit-of-fabel-quiz';

    private static $instance_count = 0;

    public function __construct($register_lifecycle_hooks = true) {
        add_action('init', [$this, 'load_textdomain'], 0);
        add_action('init', [$this, 'register_post_type']);
        add_action('acf/init', [$this, 'register_acf']);
        add_action('wp_enqueue_scripts', [$this, 'register_assets']);
        add_action('enqueue_block_editor_assets', [$this, 'register_assets']);
        add_action('admin_notices', [$this, 'acf_admin_notice']);

        add_shortcode(self::SHORTCODE, [$this, 'shortcode']);

        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'add_admin_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_admin_columns'], 10, 2);

        if ($register_lifecycle_hooks) {
            register_activation_hook(__FILE__, [__CLASS__, 'activate']);
            register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
        }
    }

    public static function activate() {
        $plugin = new self(false);
        $plugin->register_post_type();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function load_textdomain() {
        load_plugin_textdomain('feit-of-fabel-quiz', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_post_type() {
        $labels = [
            'name'                  => __('Quizzes', 'feit-of-fabel-quiz'),
            'singular_name'         => __('Quiz', 'feit-of-fabel-quiz'),
            'menu_name'             => __('Quizzes', 'feit-of-fabel-quiz'),
            'add_new'               => __('Nieuwe quiz', 'feit-of-fabel-quiz'),
            'add_new_item'          => __('Nieuwe quiz toevoegen', 'feit-of-fabel-quiz'),
            'edit_item'             => __('Quiz bewerken', 'feit-of-fabel-quiz'),
            'new_item'              => __('Nieuwe quiz', 'feit-of-fabel-quiz'),
            'view_item'             => __('Quiz bekijken', 'feit-of-fabel-quiz'),
            'search_items'          => __('Quizzes zoeken', 'feit-of-fabel-quiz'),
            'not_found'             => __('Geen quizzes gevonden.', 'feit-of-fabel-quiz'),
            'not_found_in_trash'    => __('Geen quizzes in de prullenbak.', 'feit-of-fabel-quiz'),
            'item_published'        => __('Quiz gepubliceerd.', 'feit-of-fabel-quiz'),
            'item_updated'          => __('Quiz bijgewerkt.', 'feit-of-fabel-quiz'),
        ];

        $args = [
            'labels'             => $labels,
            'public'             => false,
            'publicly_queryable' => false,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-editor-help',
            'menu_position'      => 25,
            'supports'           => ['title'],
            'has_archive'        => false,
            'rewrite'            => false,
            'map_meta_cap'       => true,
        ];

        register_post_type(self::POST_TYPE, apply_filters('fof_quiz_post_type_args', $args));
    }

    public function register_assets() {
        $base_url = plugin_dir_url(__FILE__) . 'assets/';
        $base_path = plugin_dir_path(__FILE__) . 'assets/';
        $style_version = file_exists($base_path . 'quiz.css') ? (string) filemtime($base_path . 'quiz.css') : self::VERSION;
        $script_version = file_exists($base_path . 'quiz.js') ? (string) filemtime($base_path . 'quiz.js') : self::VERSION;

        wp_register_style('fof-quiz', $base_url . 'quiz.css', [], $style_version);
        wp_register_script('fof-quiz', $base_url . 'quiz.js', [], $script_version, true);

        wp_localize_script('fof-quiz', 'fofQuizSettings', [
            'dataLayer' => (bool) apply_filters('fof_quiz_enable_data_layer', false),
        ]);

        // Ensure assets are printed in the document head/footer for normal post content.
        // The render callback remains as a fallback for widgets and page builders.
        if (!is_admin() && is_singular()) {
            $post = get_post();
            if ($post instanceof WP_Post && (
                has_shortcode($post->post_content, self::SHORTCODE)
                || has_block('acf/' . self::BLOCK_NAME, $post)
            )) {
                wp_enqueue_style('fof-quiz');
                wp_enqueue_script('fof-quiz');
            }
        }
    }

    public function enqueue_assets() {
        if (!wp_style_is('fof-quiz', 'registered') || !wp_script_is('fof-quiz', 'registered')) {
            $this->register_assets();
        }

        wp_enqueue_style('fof-quiz');
        wp_enqueue_script('fof-quiz');
    }

    public function register_acf() {
        if (!function_exists('acf_add_local_field_group')) {
            return;
        }

        $this->register_quiz_fields();
        $this->register_block_fields();

        if (function_exists('acf_register_block_type')) {
            acf_register_block_type([
                'name'            => self::BLOCK_NAME,
                'title'           => __('Feit of fabel quiz', 'feit-of-fabel-quiz'),
                'description'     => __('Toont een Feit of Fabel quiz.', 'feit-of-fabel-quiz'),
                'category'        => 'widgets',
                'icon'            => 'editor-help',
                'keywords'        => ['quiz', 'feit', 'fabel', 'waar', 'niet waar'],
                'mode'            => 'preview',
                'render_callback' => [$this, 'render_block'],
                'enqueue_assets'  => [$this, 'enqueue_assets'],
                'supports'        => [
                    'align' => ['wide', 'full'],
                    'mode'  => false,
                    'jsx'   => false,
                ],
            ]);
        }
    }

    private function register_quiz_fields() {
        acf_add_local_field_group([
            'key' => 'group_fof_quiz_content',
            'title' => __('Quizinhoud', 'feit-of-fabel-quiz'),
            'fields' => [
                [
                    'key' => 'field_fof_description',
                    'label' => __('Beschrijving', 'feit-of-fabel-quiz'),
                    'name' => 'fof_description',
                    'type' => 'wysiwyg',
                    'instructions' => __('Optionele introductie. Deze wordt alleen getoond als dit bij het blok of de shortcode is ingeschakeld.', 'feit-of-fabel-quiz'),
                    'tabs' => 'visual',
                    'toolbar' => 'basic',
                    'media_upload' => 0,
                ],
                [
                    'key' => 'field_fof_questions',
                    'label' => __('Vragen', 'feit-of-fabel-quiz'),
                    'name' => 'fof_questions',
                    'type' => 'repeater',
                    'required' => 1,
                    'min' => 1,
                    'layout' => 'block',
                    'button_label' => __('Vraag toevoegen', 'feit-of-fabel-quiz'),
                    'collapsed' => 'field_fof_question_text',
                    'sub_fields' => [
                        [
                            'key' => 'field_fof_question_text',
                            'label' => __('Vraag / stelling', 'feit-of-fabel-quiz'),
                            'name' => 'question',
                            'type' => 'textarea',
                            'required' => 1,
                            'rows' => 3,
                            'new_lines' => '',
                            'wrapper' => ['width' => '70'],
                        ],
                        [
                            'key' => 'field_fof_correct_answer',
                            'label' => __('Juiste antwoord', 'feit-of-fabel-quiz'),
                            'name' => 'correct_answer',
                            'type' => 'button_group',
                            'required' => 1,
                            'choices' => [
                                '1' => __('Feit (waar)', 'feit-of-fabel-quiz'),
                                '0' => __('Fabel (niet waar)', 'feit-of-fabel-quiz'),
                            ],
                            'default_value' => '1',
                            'return_format' => 'value',
                            'layout' => 'horizontal',
                            'wrapper' => ['width' => '30'],
                        ],
                        [
                            'key' => 'field_fof_explanation',
                            'label' => __('Uitleg', 'feit-of-fabel-quiz'),
                            'name' => 'explanation',
                            'type' => 'wysiwyg',
                            'instructions' => __('Optioneel. Wordt getoond nadat de bezoeker antwoord heeft gegeven.', 'feit-of-fabel-quiz'),
                            'tabs' => 'visual',
                            'toolbar' => 'basic',
                            'media_upload' => 0,
                            'wrapper' => ['width' => '65'],
                        ],
                        [
                            'key' => 'field_fof_image',
                            'label' => __('Afbeelding', 'feit-of-fabel-quiz'),
                            'name' => 'image',
                            'type' => 'image',
                            'instructions' => __('Optioneel. Een vierkante of liggende afbeelding werkt het best.', 'feit-of-fabel-quiz'),
                            'return_format' => 'id',
                            'preview_size' => 'medium',
                            'library' => 'all',
                            'wrapper' => ['width' => '35'],
                        ],
                    ],
                ],
                [
                    'key' => 'field_fof_texts_tab',
                    'label' => __('Teksten en resultaat', 'feit-of-fabel-quiz'),
                    'type' => 'tab',
                    'placement' => 'top',
                ],
                [
                    'key' => 'field_fof_true_label',
                    'label' => __('Feit-knop', 'feit-of-fabel-quiz'),
                    'name' => 'fof_true_label',
                    'type' => 'text',
                    'default_value' => 'Waar',
                    'wrapper' => ['width' => '25'],
                ],
                [
                    'key' => 'field_fof_false_label',
                    'label' => __('Fabel-knop', 'feit-of-fabel-quiz'),
                    'name' => 'fof_false_label',
                    'type' => 'text',
                    'default_value' => 'Niet waar',
                    'wrapper' => ['width' => '25'],
                ],
                [
                    'key' => 'field_fof_next_label',
                    'label' => __('Volgende-knop', 'feit-of-fabel-quiz'),
                    'name' => 'fof_next_label',
                    'type' => 'text',
                    'default_value' => 'Volgende vraag',
                    'wrapper' => ['width' => '25'],
                ],
                [
                    'key' => 'field_fof_finish_label',
                    'label' => __('Resultaat-knop', 'feit-of-fabel-quiz'),
                    'name' => 'fof_finish_label',
                    'type' => 'text',
                    'default_value' => 'Bekijk resultaat',
                    'wrapper' => ['width' => '25'],
                ],
                [
                    'key' => 'field_fof_result_heading',
                    'label' => __('Resultaattitel', 'feit-of-fabel-quiz'),
                    'name' => 'fof_result_heading',
                    'type' => 'text',
                    'default_value' => 'Goed gedaan! 🎉',
                    'wrapper' => ['width' => '50'],
                ],
                [
                    'key' => 'field_fof_score_text',
                    'label' => __('Scoretekst', 'feit-of-fabel-quiz'),
                    'name' => 'fof_score_text',
                    'type' => 'text',
                    'instructions' => __('Gebruik {score} en {total} als variabelen.', 'feit-of-fabel-quiz'),
                    'default_value' => 'Je had {score} van de {total} vragen goed.',
                    'wrapper' => ['width' => '50'],
                ],
                [
                    'key' => 'field_fof_share_text',
                    'label' => __('Deeltekst', 'feit-of-fabel-quiz'),
                    'name' => 'fof_share_text',
                    'type' => 'text',
                    'instructions' => __('Gebruik {score}, {total} en {quiz} als variabelen.', 'feit-of-fabel-quiz'),
                    'default_value' => 'Ik had {score} van de {total} vragen goed bij {quiz}. Wat is jouw score?',
                ],
            ],
            'location' => [[[
                'param' => 'post_type',
                'operator' => '==',
                'value' => self::POST_TYPE,
            ]]],
            'menu_order' => 0,
            'position' => 'normal',
            'style' => 'default',
            'label_placement' => 'top',
            'instruction_placement' => 'label',
            'active' => true,
            'show_in_rest' => 0,
        ]);
    }

    private function register_block_fields() {
        acf_add_local_field_group([
            'key' => 'group_fof_quiz_block',
            'title' => __('Quizinstellingen', 'feit-of-fabel-quiz'),
            'fields' => [
                [
                    'key' => 'field_fof_selected_quiz',
                    'label' => __('Quiz', 'feit-of-fabel-quiz'),
                    'name' => 'fof_selected_quiz',
                    'type' => 'post_object',
                    'required' => 1,
                    'post_type' => [self::POST_TYPE],
                    'post_status' => ['publish'],
                    'return_format' => 'id',
                    'ui' => 1,
                ],
                [
                    'key' => 'field_fof_show_intro',
                    'label' => __('Titel en beschrijving tonen', 'feit-of-fabel-quiz'),
                    'name' => 'fof_show_intro',
                    'type' => 'true_false',
                    'default_value' => 0,
                    'ui' => 1,
                ],
            ],
            'location' => [[[
                'param' => 'block',
                'operator' => '==',
                'value' => 'acf/' . self::BLOCK_NAME,
            ]]],
            'active' => true,
        ]);
    }

    public function render_block($block, $content = '', $is_preview = false, $post_id = 0) {
        $quiz_id = (int) get_field('fof_selected_quiz');
        $show_intro = (bool) get_field('fof_show_intro');

        if (!$quiz_id) {
            if ($is_preview) {
                echo '<div class="fof-quiz-placeholder"><strong>' . esc_html__('Feit of fabel quiz', 'feit-of-fabel-quiz') . '</strong><br>' . esc_html__('Selecteer een quiz in de blokinstellingen.', 'feit-of-fabel-quiz') . '</div>';
            }
            return;
        }

        $classes = ['fof-quiz-block'];
        if (!empty($block['className'])) {
            $classes[] = $this->sanitize_class_list($block['className']);
        }
        if (!empty($block['align'])) {
            $classes[] = 'align' . sanitize_html_class($block['align']);
        }

        echo $this->render_quiz($quiz_id, $show_intro, implode(' ', $classes)); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function shortcode($atts = []) {
        $atts = shortcode_atts([
            'id' => 0,
            'slug' => '',
            'show_intro' => 'false',
            'class' => '',
        ], $atts, self::SHORTCODE);

        $quiz_id = (int) $atts['id'];
        if (!$quiz_id && $atts['slug'] !== '') {
            $quiz = get_page_by_path(sanitize_title($atts['slug']), OBJECT, self::POST_TYPE);
            $quiz_id = $quiz instanceof WP_Post ? (int) $quiz->ID : 0;
        }

        $show_intro = filter_var($atts['show_intro'], FILTER_VALIDATE_BOOLEAN);
        $class = 'fof-quiz-shortcode';
        if ($atts['class'] !== '') {
            $class .= ' ' . $this->sanitize_class_list($atts['class']);
        }

        return $this->render_quiz($quiz_id, $show_intro, $class);
    }

    private function render_quiz($quiz_id, $show_intro = false, $wrapper_class = '') {
        if (!function_exists('get_field')) {
            return current_user_can('activate_plugins')
                ? '<div class="alert alert-warning">' . esc_html__('Feit of Fabel Quiz vereist Advanced Custom Fields Pro.', 'feit-of-fabel-quiz') . '</div>'
                : '';
        }

        $quiz = get_post($quiz_id);
        if (!$quiz instanceof WP_Post || $quiz->post_type !== self::POST_TYPE) {
            return current_user_can('edit_posts')
                ? '<div class="alert alert-warning">' . esc_html__('De geselecteerde quiz bestaat niet.', 'feit-of-fabel-quiz') . '</div>'
                : '';
        }

        if ($quiz->post_status !== 'publish' && !current_user_can('edit_post', $quiz_id)) {
            return '';
        }

        $questions = get_field('fof_questions', $quiz_id);
        if (!is_array($questions) || count($questions) === 0) {
            return current_user_can('edit_post', $quiz_id)
                ? '<div class="alert alert-warning">' . esc_html__('Voeg minimaal één vraag toe aan deze quiz.', 'feit-of-fabel-quiz') . '</div>'
                : '';
        }

        $this->enqueue_assets();
        self::$instance_count++;
        $instance_id = 'fof-quiz-' . (int) $quiz_id . '-' . self::$instance_count;
        $title = get_the_title($quiz_id);
        $total = count($questions);
        $settings = $this->get_quiz_settings($quiz_id);
        $wrapper_class = trim('fof-quiz-wrap my-5 ' . $wrapper_class);

        ob_start();
        ?>
        <div class="<?php echo esc_attr($wrapper_class); ?>">
            <section
                id="<?php echo esc_attr($instance_id); ?>"
                class="fof-quiz mx-auto"
                data-fof-quiz
                data-quiz-id="<?php echo esc_attr((string) $quiz_id); ?>"
                data-quiz-title="<?php echo esc_attr($title); ?>"
                data-score-template="<?php echo esc_attr($settings['score_text']); ?>"
                data-share-template="<?php echo esc_attr($settings['share_text']); ?>"
            >
                <?php if ($show_intro) : ?>
                    <header class="fof-quiz__intro text-center mx-auto mb-5">
                        <h2 class="display-5 fw-bold lh-sm pt-0 mb-3"><?php echo esc_html($title); ?></h2>
                        <?php if ($settings['description'] !== '') : ?>
                            <div class="fof-quiz__description fs-5"><?php echo wp_kses_post($settings['description']); ?></div>
                        <?php endif; ?>
                    </header>
                <?php endif; ?>

                <div class="fof-quiz__questions">
                    <?php foreach ($questions as $index => $question) : ?>
                        <?php echo $this->render_question($question, $index, $total, $settings); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <?php endforeach; ?>
                </div>

                <section class="fof-quiz__result card border-0 rounded-5 shadow text-center p-4 p-sm-5" data-fof-result hidden aria-live="polite" aria-labelledby="<?php echo esc_attr($instance_id); ?>-result-title">
                    <div class="mx-auto py-lg-3 w-100">
                        <h2 id="<?php echo esc_attr($instance_id); ?>-result-title" class="fof-quiz__result-title display-6 fw-bold lh-sm pt-0 mb-4" tabindex="-1"><?php echo esc_html($settings['result_heading']); ?></h2>
                        <p class="fs-4 lh-base mb-5" data-fof-score></p>
                        <p class="fs-5 fw-semibold lh-base mb-3"><?php esc_html_e('Deel je resultaat', 'feit-of-fabel-quiz'); ?></p>
                        <div class="row g-3 justify-content-center">
                            <div class="col-sm-5 d-grid">
                                <button type="button" class="btn btn-primary rounded-pill fw-bold py-3 px-4 d-inline-flex align-items-center justify-content-center gap-2 text-white" data-fof-share="facebook" aria-label="<?php esc_attr_e('Deel je resultaat op Facebook', 'feit-of-fabel-quiz'); ?>">
                                    <svg class="fof-icon fof-icon--share" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M13.5 21v-8h2.7l.4-3h-3.1V8.1c0-.9.3-1.5 1.6-1.5h1.7V4a22 22 0 0 0-2.5-.1c-2.5 0-4.2 1.5-4.2 4.3V10H7.3v3h2.8v8h3.4Z"/></svg>
                                    <span>Facebook</span>
                                </button>
                            </div>
                            <div class="col-sm-5 d-grid">
                                <button type="button" class="btn btn-primary rounded-pill fw-bold py-3 px-4 d-inline-flex align-items-center justify-content-center gap-2 text-white" data-fof-share="x" aria-label="<?php esc_attr_e('Deel je resultaat op X', 'feit-of-fabel-quiz'); ?>">
                                    <svg class="fof-icon fof-icon--share" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path fill="currentColor" d="M18.9 2H22l-6.8 7.8L23.2 22h-6.3l-4.9-6.4L6.4 22H3.3l7.3-8.4L2.2 2h6.4l4.4 5.8L18.9 2Zm-1.1 17.6h1.7L7.7 4.3H5.9l11.9 15.3Z"/></svg>
                                    <span>X</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>
            </section>
        </div>
        <?php
        $html = (string) ob_get_clean();

        return apply_filters('fof_quiz_rendered_html', $html, $quiz_id, $settings);
    }

    private function render_question($question, $index, $total, $settings) {
        $question_text = isset($question['question']) ? trim((string) $question['question']) : '';
        if ($question_text === '') {
            return '';
        }

        $correct_answer = !empty($question['correct_answer']) ? '1' : '0';
        $explanation = !empty($question['explanation']) ? (string) $question['explanation'] : '';
        $image_id = $this->get_image_id(isset($question['image']) ? $question['image'] : 0);
        $number = (int) $index + 1;
        $is_last = $number === (int) $total;
        $image_size = apply_filters('fof_quiz_image_size', 'large', $image_id, $question);

        ob_start();
        ?>

        <article class="fof-question<?php echo $index === 0 ? ' is-active' : ''; ?>" data-fof-question data-index="<?php echo esc_attr((string) $number); ?>" data-correct="<?php echo esc_attr($correct_answer); ?>"<?php echo $index === 0 ? '' : ' hidden'; ?>>
            <div class="card border-0 rounded-5 shadow overflow-hidden position-relative">
                <div class="row g-0 align-items-stretch">
                    <?php if ($image_id) : ?>
                        <div class="col-lg-6 fof-question__media">
                            <?php echo wp_get_attachment_image($image_id, $image_size, false, [
                                'class' => 'fof-question__image w-100 h-100',
                                'loading' => $index === 0 ? 'eager' : 'lazy',
                                'decoding' => 'async',
                            ]); ?>
                        </div>
                    <?php endif; ?>
                    <div class="<?php echo $image_id ? 'col-lg-6' : 'col-12'; ?> fof-question__content d-flex flex-column justify-content-between p-4 p-lg-5">
                        <div>
                            <p class="fs-5 lh-base mb-0">
                                <?php
                                printf(
                                    esc_html__('Vraag %1$s van %2$s', 'feit-of-fabel-quiz'),
                                    '<strong>' . esc_html((string) $number) . '</strong>',
                                    '<strong>' . esc_html((string) $total) . '</strong>'
                                );
                                ?>
                            </p>
                            <h2 class="fs-2 fw-bold lh-sm pt-0 mt-4 mb-5"><?php echo nl2br(esc_html($question_text)); ?></h2>
                        </div>
                        <div class="fof-question__answers row g-3" role="group" aria-label="<?php esc_attr_e('Kies waar of niet waar', 'feit-of-fabel-quiz'); ?>">
                            <div class="col-sm-6">
                                <button type="button" class="fof-question__answer btn btn-success rounded-pill py-3 px-4 w-100 text-white" data-fof-answer="1" aria-pressed="false"><?php echo esc_html($settings['true_label']); ?></button>
                            </div>
                            <div class="col-sm-6">
                                <button type="button" class="fof-question__answer btn btn-danger rounded-pill py-3 px-4 w-100 text-white" data-fof-answer="0" aria-pressed="false"><?php echo esc_html($settings['false_label']); ?></button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="fof-question__feedback position-absolute top-0 start-0 w-100 h-100 z-2 overflow-hidden" data-fof-feedback hidden aria-live="polite" tabindex="-1">
                    <div class="fof-question__feedback-scroll h-100 overflow-auto d-flex flex-column p-4 p-lg-5" data-fof-feedback-scroll>
                        <div class="col-12 col-lg-10 mx-auto my-auto">
                            <div class="fof-question__feedback-header d-flex align-items-center gap-3 pb-2">
                                <span class="fof-question__feedback-icon d-flex align-items-center justify-content-center rounded-circle text-white fs-2 flex-shrink-0" data-fof-feedback-icon data-icon="check" aria-hidden="true">
                                    <svg class="fof-icon fof-icon--feedback fof-icon--check" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                                    <svg class="fof-icon fof-icon--feedback fof-icon--cross" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m6 6 12 12M18 6 6 18"/></svg>
                                </span>
                                <p class="fof-question__feedback-title fs-3 fw-bold mb-0" data-fof-feedback-title></p>
                            </div>
                            <span class="btn rounded-pill fw-semibold py-2 px-3 d-inline-flex align-items-center text-white mb-4" data-fof-feedback-choice></span>
                            <?php if ($explanation !== '') : ?>
                                <div class="fof-question__explanation fs-5 lh-lg"><?php echo wp_kses_post($explanation); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="row justify-content-end flex-shrink-0 mt-4">
                            <div class="col-12 col-sm-auto d-grid">
                                <button type="button" class="btn btn-primary rounded-pill fw-bold py-2 px-4 d-inline-flex align-items-center justify-content-center gap-2" data-fof-next>
                                    <span><?php echo esc_html($is_last ? $settings['finish_label'] : $settings['next_label']); ?></span>
                                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="fof-question__scroll-indicator btn btn-primary rounded-circle position-absolute bottom-0 start-50 translate-middle-x d-inline-flex align-items-center justify-content-center shadow mb-3" data-fof-feedback-scroll-indicator hidden aria-label="<?php esc_attr_e('Scroll omlaag', 'feit-of-fabel-quiz'); ?>">
                        <i class="bi bi-chevron-down fs-5" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private function get_quiz_settings($quiz_id) {
        $settings = [
            'description' => (string) get_field('fof_description', $quiz_id),
            'true_label' => $this->field_or_default('fof_true_label', $quiz_id, 'Waar'),
            'false_label' => $this->field_or_default('fof_false_label', $quiz_id, 'Niet waar'),
            'next_label' => $this->field_or_default('fof_next_label', $quiz_id, 'Volgende vraag'),
            'finish_label' => $this->field_or_default('fof_finish_label', $quiz_id, 'Bekijk resultaat'),
            'result_heading' => $this->field_or_default('fof_result_heading', $quiz_id, 'Goed gedaan! 🎉'),
            'score_text' => $this->field_or_default('fof_score_text', $quiz_id, 'Je had {score} van de {total} vragen goed.'),
            'share_text' => $this->field_or_default('fof_share_text', $quiz_id, 'Ik had {score} van de {total} vragen goed bij {quiz}. Wat is jouw score?'),
        ];

        return apply_filters('fof_quiz_settings', $settings, $quiz_id);
    }

    private function field_or_default($field, $post_id, $default) {
        $value = trim((string) get_field($field, $post_id));
        return $value !== '' ? $value : $default;
    }

    private function get_image_id($image) {
        if (is_numeric($image)) {
            return (int) $image;
        }
        if (is_array($image) && !empty($image['ID'])) {
            return (int) $image['ID'];
        }
        return 0;
    }

    private function sanitize_class_list($classes) {
        $class_list = preg_split('/\s+/', trim((string) $classes));
        if (!is_array($class_list)) {
            return '';
        }

        return implode(' ', array_filter(array_map('sanitize_html_class', $class_list)));
    }

    public function acf_admin_notice() {
        if (function_exists('acf_add_local_field_group') || !current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>Feit of Fabel Quiz:</strong> ' . esc_html__('activeer Advanced Custom Fields Pro om quizvragen en het Gutenberg-blok te beheren.', 'feit-of-fabel-quiz') . '</p></div>';
    }

    public function add_admin_columns($columns) {
        $columns['fof_shortcode'] = __('Shortcode', 'feit-of-fabel-quiz');
        return $columns;
    }

    public function render_admin_columns($column, $post_id) {
        if ($column === 'fof_shortcode') {
            echo '<code>[' . esc_html(self::SHORTCODE) . ' id=&quot;' . esc_html((string) $post_id) . '&quot;]</code>';
        }
    }
}

new FOF_Quiz_Plugin();
