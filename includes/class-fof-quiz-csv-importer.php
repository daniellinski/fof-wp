<?php
/**
 * CSV importer for Feit of Fabel quizzes.
 */

defined('ABSPATH') || exit;

final class FOF_Quiz_CSV_Importer {
    const PAGE_SLUG = 'fof-quiz-import';
    const ACTION = 'fof_quiz_import_csv';
    const NONCE_ACTION = 'fof_quiz_import_csv';
    const RESULT_QUERY_ARG = 'fof_import_result';
    const RESULT_TTL = 600;
    const MAX_FILE_SIZE = 2097152;
    const MAX_ROWS = 1000;

    private $post_type;
    private $questions_field_key;

    public function __construct($post_type, $questions_field_key) {
        $this->post_type = (string) $post_type;
        $this->questions_field_key = (string) $questions_field_key;

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_post_' . self::ACTION, [$this, 'handle_import']);
    }

    public function register_menu() {
        add_submenu_page(
            'edit.php?post_type=' . $this->post_type,
            __('Import CSV', 'feit-of-fabel-quiz'),
            __('Import CSV', 'feit-of-fabel-quiz'),
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_page']
        );
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen toestemming om quizzes te importeren.', 'feit-of-fabel-quiz'));
        }

        $result = $this->get_result_from_request();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Quizzes importeren uit CSV', 'feit-of-fabel-quiz'); ?></h1>

            <?php if (!function_exists('update_field')) : ?>
                <div class="notice notice-error">
                    <p><?php esc_html_e('Advanced Custom Fields Pro is vereist om quizzes te importeren.', 'feit-of-fabel-quiz'); ?></p>
                </div>
            <?php else : ?>
                <?php $this->render_result($result); ?>

                <p><?php esc_html_e('Maak één nieuwe quiz aan vanuit een CSV-bestand. De quiz wordt als concept opgeslagen.', 'feit-of-fabel-quiz'); ?></p>
                <p>
                    <?php esc_html_e('Verwachte kolommen:', 'feit-of-fabel-quiz'); ?>
                    <code>question,correct_answer,explanation</code>
                </p>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="fof-quiz-title"><?php esc_html_e('Quiztitel', 'feit-of-fabel-quiz'); ?></label></th>
                            <td>
                                <input type="text" id="fof-quiz-title" name="quiz_title" class="regular-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="fof-quiz-csv"><?php esc_html_e('CSV-bestand', 'feit-of-fabel-quiz'); ?></label></th>
                            <td>
                                <input type="file" id="fof-quiz-csv" name="quiz_csv" accept=".csv" required>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('CSV uploaden en quiz aanmaken', 'feit-of-fabel-quiz')); ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    public function handle_import() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen toestemming om quizzes te importeren.', 'feit-of-fabel-quiz'));
        }

        check_admin_referer(self::NONCE_ACTION);

        if (!function_exists('update_field')) {
            $this->redirect_with_result([
                'type' => 'error',
                'messages' => [__('Advanced Custom Fields Pro is vereist om quizzes te importeren.', 'feit-of-fabel-quiz')],
            ]);
        }

        $title = isset($_POST['quiz_title']) ? sanitize_text_field(wp_unslash($_POST['quiz_title'])) : '';
        $errors = [];

        if ($title === '') {
            $errors[] = __('Vul een quiztitel in.', 'feit-of-fabel-quiz');
        }

        if (empty($_FILES['quiz_csv']) || !is_array($_FILES['quiz_csv'])) {
            $errors[] = __('Selecteer een CSV-bestand.', 'feit-of-fabel-quiz');
        } else {
            $file = $_FILES['quiz_csv'];

            if ((int) $file['error'] !== UPLOAD_ERR_OK) {
                $errors[] = __('Het CSV-bestand kon niet worden geüpload.', 'feit-of-fabel-quiz');
            } elseif ((int) $file['size'] > self::MAX_FILE_SIZE) {
                $errors[] = __('Het CSV-bestand is groter dan 2 MB.', 'feit-of-fabel-quiz');
            } elseif (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                $errors[] = __('Het geüploade bestand is ongeldig.', 'feit-of-fabel-quiz');
            }
        }

        if (!empty($errors)) {
            $this->redirect_with_result([
                'type' => 'error',
                'messages' => $errors,
            ]);
        }

        $parsed = $this->parse_csv($_FILES['quiz_csv']['tmp_name']);

        if (!empty($parsed['errors'])) {
            $this->redirect_with_result([
                'type' => 'error',
                'messages' => $parsed['errors'],
                'debug' => $parsed['debug'],
            ]);
        }

        $post_id = wp_insert_post([
            'post_type' => $this->post_type,
            'post_title' => $title,
            'post_status' => 'draft',
            'post_author' => get_current_user_id(),
        ], true);

        if (is_wp_error($post_id)) {
            $this->redirect_with_result([
                'type' => 'error',
                'messages' => [$post_id->get_error_message()],
            ]);
        }

        $updated = update_field($this->questions_field_key, $parsed['rows'], $post_id);
        if (!$updated) {
            wp_delete_post($post_id, true);
            $this->redirect_with_result([
                'type' => 'error',
                'messages' => [__('De vragen konden niet worden opgeslagen. De conceptquiz is niet aangemaakt.', 'feit-of-fabel-quiz')],
            ]);
        }

        $this->redirect_with_result([
            'type' => 'success',
            'post_id' => (int) $post_id,
            'row_count' => count($parsed['rows']),
        ]);
    }

    private function parse_csv($path) {
        $rows = [];
        $errors = [];
        $debug = [];
        $handle = fopen($path, 'rb');

        if (!$handle) {
            return [
                'rows' => [],
                'errors' => [__('Het CSV-bestand kon niet worden gelezen.', 'feit-of-fabel-quiz')],
                'debug' => [],
            ];
        }

        $raw_header = fgetcsv($handle, 0, ',');
        if (!is_array($raw_header)) {
            fclose($handle);
            return [
                'rows' => [],
                'errors' => [__('Het CSV-bestand is leeg.', 'feit-of-fabel-quiz')],
                'debug' => [],
            ];
        }

        $header = array_map([$this, 'normalize_header'], $raw_header);
        $debug[] = sprintf(
            /* translators: %s: parsed CSV header as JSON. */
            __('Gelezen kopregel: %s', 'feit-of-fabel-quiz'),
            $this->format_debug_value($raw_header)
        );

        if ($header !== ['question', 'correct_answer', 'explanation']) {
            fclose($handle);
            return [
                'rows' => [],
                'errors' => [__('De CSV-kopregel moet exact zijn: question,correct_answer,explanation.', 'feit-of-fabel-quiz')],
                'debug' => $debug,
            ];
        }

        $line_number = 1;
        while (($fields = fgetcsv($handle, 0, ',')) !== false) {
            $line_number++;

            if ($this->is_empty_row($fields)) {
                continue;
            }

            if (count($fields) !== 3) {
                $errors[] = sprintf(
                    /* translators: %d: CSV line number. */
                    __('Regel %d moet precies drie kolommen bevatten.', 'feit-of-fabel-quiz'),
                    $line_number
                );
                $debug[] = sprintf(
                    /* translators: 1: CSV line number, 2: parsed CSV fields as JSON. */
                    __('Regel %1$d ingelezen als: %2$s', 'feit-of-fabel-quiz'),
                    $line_number,
                    $this->format_debug_value($fields)
                );
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $errors[] = sprintf(
                    /* translators: %d: maximum number of rows. */
                    __('Het CSV-bestand bevat meer dan %d vragen.', 'feit-of-fabel-quiz'),
                    self::MAX_ROWS
                );
                break;
            }

            $question = sanitize_textarea_field((string) $fields[0]);
            $answer = $this->normalize_answer($fields[1]);
            $explanation = trim((string) $fields[2]);
            $row_has_error = false;

            if ($question === '') {
                $row_has_error = true;
                $errors[] = sprintf(
                    /* translators: %d: CSV line number. */
                    __('Regel %d bevat geen vraag.', 'feit-of-fabel-quiz'),
                    $line_number
                );
            }

            if ($answer === null) {
                $row_has_error = true;
                $errors[] = sprintf(
                    /* translators: 1: CSV line number, 2: parsed answer value. */
                    __('Regel %1$d bevat geen geldig antwoord. Gebruik true of false. Gelezen waarde: %2$s.', 'feit-of-fabel-quiz'),
                    $line_number,
                    $this->format_debug_value($fields[1])
                );
            }

            if ($row_has_error) {
                $debug[] = sprintf(
                    /* translators: 1: CSV line number, 2: parsed CSV fields as JSON. */
                    __('Regel %1$d ingelezen als: %2$s', 'feit-of-fabel-quiz'),
                    $line_number,
                    $this->format_debug_value($fields)
                );
            }

            if ($question === '' || $answer === null) {
                continue;
            }

            $rows[] = [
                'question' => $question,
                'correct_answer' => $answer,
                'explanation' => $explanation === '' ? '' : wpautop(wp_kses_post($explanation)),
            ];
        }

        fclose($handle);

        if (empty($rows) && empty($errors)) {
            $errors[] = __('Het CSV-bestand bevat geen vragen.', 'feit-of-fabel-quiz');
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'debug' => $debug,
        ];
    }

    private function normalize_header($value) {
        $value = (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        return strtolower(trim($value));
    }

    private function normalize_answer($value) {
        $value = strtolower(trim((string) $value));

        if (in_array($value, ['true', '1', 'yes', 'waar'], true)) {
            return '1';
        }

        if (in_array($value, ['false', '0', 'no', 'niet waar'], true)) {
            return '0';
        }

        return null;
    }

    private function is_empty_row($fields) {
        if (!is_array($fields)) {
            return true;
        }

        foreach ($fields as $field) {
            if (trim((string) $field) !== '') {
                return false;
            }
        }

        return true;
    }

    private function format_debug_value($value) {
        $encoded = wp_json_encode($value);

        return false === $encoded ? '[ongeldige UTF-8-waarde]' : $encoded;
    }

    private function render_result($result) {
        if (!is_array($result) || empty($result['type'])) {
            return;
        }

        if ($result['type'] === 'success') {
            $post_id = isset($result['post_id']) ? (int) $result['post_id'] : 0;
            $row_count = isset($result['row_count']) ? (int) $result['row_count'] : 0;
            $edit_link = $post_id ? get_edit_post_link($post_id) : '';
            ?>
            <div class="notice notice-success is-dismissible">
                <p>
                    <?php
                    printf(
                        esc_html__('%d vragen zijn geïmporteerd als concept.', 'feit-of-fabel-quiz'),
                        $row_count
                    );
                    ?>
                </p>
                <?php if ($edit_link) : ?>
                    <p><a class="button button-primary" href="<?php echo esc_url($edit_link); ?>"><?php esc_html_e('Quiz bewerken', 'feit-of-fabel-quiz'); ?></a></p>
                <?php endif; ?>
            </div>
            <?php
            return;
        }

        $messages = isset($result['messages']) && is_array($result['messages']) ? $result['messages'] : [];
        if (empty($messages)) {
            return;
        }
        ?>
        <div class="notice notice-error">
            <p><strong><?php esc_html_e('Importeren mislukt.', 'feit-of-fabel-quiz'); ?></strong></p>
            <ul>
                <?php foreach ($messages as $message) : ?>
                    <li><?php echo esc_html($message); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php if (!empty($result['debug']) && is_array($result['debug'])) : ?>
                <details>
                    <summary><?php esc_html_e('Debuginformatie tonen', 'feit-of-fabel-quiz'); ?></summary>
                    <pre style="max-width: 100%; overflow: auto; white-space: pre-wrap;"><?php echo esc_html(implode("\n", $result['debug'])); ?></pre>
                </details>
            <?php endif; ?>
        </div>
        <?php
    }

    private function redirect_with_result($result) {
        $token = wp_generate_uuid4();
        $transient_key = $this->get_transient_key($token);
        set_transient($transient_key, $result, self::RESULT_TTL);

        $url = add_query_arg(
            [self::RESULT_QUERY_ARG => rawurlencode($token)],
            admin_url('edit.php?post_type=' . $this->post_type . '&page=' . self::PAGE_SLUG)
        );

        wp_safe_redirect($url);
        exit;
    }

    private function get_result_from_request() {
        if (empty($_GET[self::RESULT_QUERY_ARG])) {
            return null;
        }

        $token = sanitize_text_field(wp_unslash($_GET[self::RESULT_QUERY_ARG]));
        if ($token === '') {
            return null;
        }

        $key = $this->get_transient_key($token);
        $result = get_transient($key);
        delete_transient($key);

        return is_array($result) ? $result : null;
    }

    private function get_transient_key($token) {
        return 'fof_csv_import_' . get_current_user_id() . '_' . md5((string) $token);
    }
}
