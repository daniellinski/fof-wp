(function () {
    'use strict';

    var initialized = new WeakSet();
    var settings = window.fofQuizSettings || {};
    var i18n = Object.assign({
        correctTitle: 'Goed! {verdict}',
        incorrectTitle: 'Helaas! {verdict}',
        verdictTrue: 'Dit is waar.',
        verdictFalse: 'Dit is niet waar.',
        youChose: 'Jij koos: {answer}',
        correctAnswer: 'Juiste antwoord: {answer}',
        answeredCorrectly: 'Goed beantwoord',
        answeredIncorrectly: 'Fout beantwoord'
    }, settings.i18n || {});

    function template(text, values) {
        return Object.keys(values).reduce(function (result, key) {
            return result.replace(new RegExp('\\{' + key + '\\}', 'g'), String(values[key]));
        }, text || '');
    }

    function fillAnswer(node, text, answer) {
        var parts = text.split('{answer}');
        var answerNode = document.createElement('strong');
        answerNode.textContent = answer;
        node.textContent = '';
        node.appendChild(document.createTextNode(parts[0] || ''));
        node.appendChild(answerNode);
        node.appendChild(document.createTextNode(parts.slice(1).join('{answer}')));
    }

    function Quiz(root) {
        this.root = root;
        this.questions = Array.prototype.slice.call(root.querySelectorAll('[data-fof-question]'));
        this.result = root.querySelector('[data-fof-result]');
        this.scoreNode = root.querySelector('[data-fof-score]');
        this.resultTitle = root.querySelector('[data-fof-result-title]');
        this.reviewList = root.querySelector('[data-fof-review]');
        this.answers = [];
        this.index = 0;
        this.score = 0;
        this.started = false;
        this.quizId = root.getAttribute('data-quiz-id') || '';
        this.quizTitle = root.getAttribute('data-quiz-title') || '';
        this.total = this.questions.length;
        this.bind();
        root.classList.add('is-ready');
    }

    Quiz.prototype.bind = function () {
        var quiz = this;

        this.questions.forEach(function (question) {
            question.querySelectorAll('[data-fof-answer]').forEach(function (button) {
                button.addEventListener('click', function () {
                    quiz.answer(question, button);
                });
            });

            var next = question.querySelector('[data-fof-next]');
            if (next) {
                next.addEventListener('click', function () {
                    quiz.next();
                });
            }
        });

        this.root.querySelectorAll('[data-fof-share]').forEach(function (button) {
            button.addEventListener('click', function () {
                quiz.share(button.getAttribute('data-fof-share'));
            });
        });
    };

    Quiz.prototype.answer = function (question, selected) {
        if (question.classList.contains('is-answered')) {
            return;
        }

        if (!this.started) {
            this.started = true;
            this.emit('quiz_started', this.details());
        }

        var chosen = selected.getAttribute('data-fof-answer');
        var correctAnswer = question.getAttribute('data-correct');
        var isCorrect = chosen === correctAnswer;
        var questionIndex = Number(question.getAttribute('data-index')) || this.index + 1;
        var buttons = question.querySelectorAll('[data-fof-answer]');

        question.classList.add('is-answered');
        if (!isCorrect) {
            question.classList.add('is-incorrect');
        }
        if (correctAnswer !== '1') {
            question.classList.add('is-fabel');
        }
        selected.classList.add('is-selected');
        selected.setAttribute('aria-pressed', 'true');

        buttons.forEach(function (button) {
            button.disabled = true;
            if (button.getAttribute('data-fof-answer') === correctAnswer) {
                button.classList.add('is-correct-answer');
            }
        });

        if (isCorrect) {
            this.score += 1;
        }

        var eventDetails = this.details({
            questionIndex: questionIndex,
            answer: chosen === '1',
            correctAnswer: correctAnswer === '1',
            isCorrect: isCorrect,
            score: this.score
        });
        this.emit('question_answered', eventDetails);
        this.emit(isCorrect ? 'correct_answer' : 'incorrect_answer', eventDetails);

        var trueLabel = question.querySelector('[data-fof-answer="1"]');
        var falseLabel = question.querySelector('[data-fof-answer="0"]');
        var statement = question.querySelector('[data-fof-statement]');
        this.answers.push({
            statement: statement ? statement.textContent.trim() : '',
            isCorrect: isCorrect,
            correctLabel: (correctAnswer === '1' ? trueLabel : falseLabel).textContent.trim()
        });

        var feedback = question.querySelector('[data-fof-feedback]');
        var feedbackIcon = question.querySelector('[data-fof-feedback-icon]');
        var feedbackTitle = question.querySelector('[data-fof-feedback-title]');
        var feedbackChoice = question.querySelector('[data-fof-feedback-choice]');
        var body = question.querySelector('[data-fof-body]');
        if (feedbackIcon) {
            feedbackIcon.setAttribute('data-icon', isCorrect ? 'check' : 'cross');
        }
        if (feedbackTitle) {
            feedbackTitle.textContent = template(isCorrect ? i18n.correctTitle : i18n.incorrectTitle, {
                verdict: correctAnswer === '1' ? i18n.verdictTrue : i18n.verdictFalse
            });
        }
        if (feedbackChoice) {
            fillAnswer(feedbackChoice, i18n.youChose, selected.textContent.trim());
        }
        if (feedback) {
            // The feedback replaces the question in the card; keep at least the
            // question's height so the card only grows, never jumps smaller.
            if (body) {
                feedback.style.minHeight = body.offsetHeight + 'px';
                body.hidden = true;
            }
            feedback.hidden = false;
            this.revealTop(question);
        }
    };

    Quiz.prototype.revealTop = function (element) {
        var offset = parseFloat(window.getComputedStyle(element).scrollMarginTop) || 0;
        if (element.getBoundingClientRect().top >= offset) {
            return;
        }

        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        element.scrollIntoView({ block: 'start', behavior: reducedMotion ? 'auto' : 'smooth' });
    };

    Quiz.prototype.next = function () {
        var current = this.questions[this.index];
        if (!current || !current.classList.contains('is-answered')) {
            return;
        }

        current.hidden = true;
        current.classList.remove('is-active');
        this.index += 1;

        if (this.index < this.total) {
            var nextQuestion = this.questions[this.index];
            nextQuestion.hidden = false;
            nextQuestion.classList.add('is-active');
            this.revealTop(nextQuestion);
            return;
        }

        this.complete();
    };

    Quiz.prototype.complete = function () {
        var values = {
            score: this.score,
            total: this.total,
            quiz: this.quizTitle
        };
        if (this.scoreNode) {
            this.scoreNode.textContent = template(this.root.getAttribute('data-score-template'), values);
        }
        if (this.resultTitle) {
            var ratio = this.total ? this.score / this.total : 0;
            var band = ratio >= 0.8 ? 'high' : (ratio >= 0.5 ? 'mid' : 'low');
            var heading = this.root.getAttribute('data-result-heading-' + band);
            if (heading) {
                this.resultTitle.textContent = heading;
            }
        }
        this.renderReview();
        if (this.result) {
            this.result.hidden = false;
            this.revealTop(this.result);
        }

        this.emit('quiz_completed', this.details({ score: this.score }));
        this.emit('score_achieved', this.details({ score: this.score }));
    };

    Quiz.prototype.renderReview = function () {
        if (!this.reviewList) {
            return;
        }

        var list = this.reviewList;
        list.textContent = '';
        this.answers.forEach(function (answer) {
            var item = document.createElement('li');
            item.className = 'fof-review__item d-flex align-items-start gap-3 rounded-4' + (answer.isCorrect ? ' is-correct' : ' is-incorrect');

            var icon = document.createElement('span');
            icon.className = 'fof-review__icon d-flex align-items-center justify-content-center rounded-circle text-white flex-shrink-0';
            icon.innerHTML = '<i class="bi ' + (answer.isCorrect ? 'bi-check-lg' : 'bi-x-lg') + '" aria-hidden="true"></i>';

            var status = document.createElement('span');
            status.className = 'visually-hidden';
            status.textContent = (answer.isCorrect ? i18n.answeredCorrectly : i18n.answeredIncorrectly) + ': ';

            // Divs rather than paragraphs: themes may force large paragraph margins.
            var text = document.createElement('div');
            var statement = document.createElement('div');
            statement.className = 'fof-review__statement fw-semibold';
            statement.textContent = answer.statement;
            var correct = document.createElement('div');
            correct.className = 'fof-review__answer mt-1';
            fillAnswer(correct, i18n.correctAnswer, answer.correctLabel);
            text.appendChild(status);
            text.appendChild(statement);
            text.appendChild(correct);

            item.appendChild(icon);
            item.appendChild(text);
            list.appendChild(item);
        });
    };

    Quiz.prototype.share = function (platform) {
        var pageUrl = window.location.href.split('#')[0];
        var text = template(this.root.getAttribute('data-share-template'), {
            score: this.score,
            total: this.total,
            quiz: this.quizTitle
        });
        var shareUrl = '';

        if (platform === 'facebook') {
            shareUrl = 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(pageUrl) + '&quote=' + encodeURIComponent(text);
        } else if (platform === 'x') {
            shareUrl = 'https://twitter.com/intent/tweet?text=' + encodeURIComponent(text) + '&url=' + encodeURIComponent(pageUrl);
        }

        this.emit('share_button_clicked', this.details({
            score: this.score,
            platform: platform,
            pageUrl: pageUrl
        }));

        if (shareUrl) {
            window.open(shareUrl, 'fof-quiz-share', 'width=720,height=620,noopener,noreferrer');
        }
    };

    Quiz.prototype.details = function (extra) {
        return Object.assign({
            quizId: this.quizId,
            quizTitle: this.quizTitle,
            total: this.total,
            pageUrl: window.location.href.split('#')[0]
        }, extra || {});
    };

    Quiz.prototype.emit = function (name, details) {
        var eventDetails = Object.assign({ eventName: name }, details || {});
        this.root.dispatchEvent(new CustomEvent('fofQuiz:' + name, {
            bubbles: true,
            detail: eventDetails
        }));
        this.root.dispatchEvent(new CustomEvent('fofQuiz:event', {
            bubbles: true,
            detail: eventDetails
        }));

        if (window.wp && window.wp.hooks && typeof window.wp.hooks.doAction === 'function') {
            window.wp.hooks.doAction('fofQuiz.' + name, eventDetails);
        }

        if (window.fofQuizSettings && window.fofQuizSettings.dataLayer) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(Object.assign({ event: 'fof_' + name }, eventDetails));
        }
    };

    function initialize(scope) {
        var roots = scope.matches && scope.matches('[data-fof-quiz]')
            ? [scope]
            : Array.prototype.slice.call(scope.querySelectorAll ? scope.querySelectorAll('[data-fof-quiz]') : []);

        roots.forEach(function (root) {
            if (!initialized.has(root)) {
                initialized.add(root);
                new Quiz(root);
            }
        });
    }

    initialize(document);
    document.addEventListener('DOMContentLoaded', function () {
        initialize(document);
    });

    if ('MutationObserver' in window) {
        new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) {
                        initialize(node);
                    }
                });
            });
        }).observe(document.documentElement, { childList: true, subtree: true });
    }
}());
