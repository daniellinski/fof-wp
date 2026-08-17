=== Feit of Fabel Quiz ===
Reusable ACF-managed 'feit of fabel' quizzes with a Gutenberg block, shortcode, sharing, and analytics-ready browser events.

== Requirements ==

* WordPress 6.0 or newer
* PHP 7.4 or newer
* Advanced Custom Fields Pro (the repeater field and ACF Gutenberg block are used)
* Bootstrap 5 (the plugin uses Bootstrap components, grid, and utilities for its layout)

== Updates ==

Updates are checked from the GitHub repository at https://github.com/daniellinski/fof-wp.
To publish an update, increase the plugin version in `feit-of-fabel-quiz.php` and create a GitHub release or tag with the same version number.

== Usage ==

1. Activate the plugin and ACF Pro.
2. Go to Quizzes > Nieuwe quiz and add the questions.
3. Add the “Fact of fabel quiz” Gutenberg block and select the quiz.
4. Or use `[fact_or_fiction_quiz id="123"]`.

Shortcode options:

* `id`: quiz post ID
* `slug`: quiz slug, used when no ID is supplied
* `show_intro`: `true` to show the quiz title and description (default: `false`)
* `class`: optional CSS class names for the wrapper

Example: `[fact_or_fiction_quiz slug="alcohol-feiten" show_intro="true" class="my-quiz"]`

== Analytics hooks ==

The plugin emits bubbling browser CustomEvents from the quiz element:

* `fofQuiz:quiz_started`
* `fofQuiz:question_answered`
* `fofQuiz:correct_answer`
* `fofQuiz:incorrect_answer`
* `fofQuiz:quiz_completed`
* `fofQuiz:score_achieved`
* `fofQuiz:share_button_clicked`
* `fofQuiz:event` for every event; `event.detail.eventName` identifies the event

Example:

`document.addEventListener('fofQuiz:event', function (event) { console.log(event.detail); });`

If `wp.hooks` is present, the same events run as actions such as `fofQuiz.quiz_completed`.

To push these events to a `dataLayer`, enable:

`add_filter('fof_quiz_enable_data_layer', '__return_true');`

The resulting names are prefixed with `fof_`, for example `fof_quiz_completed`.

== Customization ==

Override the plugin CSS custom properties on `.fof-quiz-wrap`, for example:

`.fof-quiz-wrap { --fof-primary: #a5027d; --fof-fact: #8cab00; --fof-fabel: #bd0000; }`

Available PHP filters include `fof_quiz_post_type_args`, `fof_quiz_settings`, `fof_quiz_image_size`, `fof_quiz_rendered_html`, and `fof_quiz_enable_data_layer`.
