(function () {
    'use strict';

    var initialized = new WeakSet();

    function template(text, values) {
        return Object.keys(values).reduce(function (result, key) {
            return result.replace(new RegExp('\\{' + key + '\\}', 'g'), String(values[key]));
        }, text || '');
    }

    function Quiz(root) {
        this.root = root;
        this.questions = Array.prototype.slice.call(root.querySelectorAll('[data-fof-question]'));
        this.result = root.querySelector('[data-fof-result]');
        this.scoreNode = root.querySelector('[data-fof-score]');
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

            var feedbackScroller = question.querySelector('[data-fof-feedback-scroll]');
            var feedbackScrollIndicator = question.querySelector('[data-fof-feedback-scroll-indicator]');
            if (feedbackScroller && feedbackScrollIndicator) {
                feedbackScroller.addEventListener('scroll', function () {
                    quiz.updateFeedbackOverflowIndicator(question);
                }, { passive: true });
                feedbackScrollIndicator.addEventListener('click', function () {
                    quiz.scrollFeedbackDown(question);
                });
            }
        });

        this.root.querySelectorAll('[data-fof-share]').forEach(function (button) {
            button.addEventListener('click', function () {
                quiz.share(button.getAttribute('data-fof-share'));
            });
        });

        this.handleFeedbackResize = function () {
            quiz.questions.forEach(function (question) {
                quiz.refreshFeedbackOverflow(question);
            });
        };
        window.addEventListener('resize', this.handleFeedbackResize);

        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(this.handleFeedbackResize);
        }
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

        var feedback = question.querySelector('[data-fof-feedback]');
        var feedbackIcon = question.querySelector('[data-fof-feedback-icon]');
        var feedbackTitle = question.querySelector('[data-fof-feedback-title]');
        var feedbackChoice = question.querySelector('[data-fof-feedback-choice]');
        if (feedbackIcon) {
            feedbackIcon.setAttribute('data-icon', correctAnswer === '1' ? 'check' : 'cross');
        }
        if (feedbackTitle) {
            feedbackTitle.textContent = correctAnswer === '1' ? 'Dit is waar!' : 'Dit is niet waar!';
        }
        if (feedbackChoice) {
            feedbackChoice.classList.remove('btn-success', 'btn-danger');
            feedbackChoice.classList.add(chosen === '1' ? 'btn-success' : 'btn-danger');
            feedbackChoice.textContent = 'Jij koos: ' + selected.textContent.trim();
        }
        if (feedback) {
            var quiz = this;
            var feedbackScroller = feedback.querySelector('[data-fof-feedback-scroll]');
            if (feedbackScroller) {
                feedbackScroller.scrollTop = 0;
            }
            feedback.hidden = false;
            this.refreshFeedbackOverflow(question);
            if (feedbackScroller) {
                window.requestAnimationFrame(function () {
                    feedbackScroller.scrollTop = 0;
                });
            }
            window.setTimeout(function () {
                if (feedbackScroller) {
                    feedbackScroller.scrollTop = 0;
                }
                quiz.refreshFeedbackOverflow(question);
            }, 350);
        }
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
        if (this.result) {
            this.result.hidden = false;
        }

        this.emit('quiz_completed', this.details({ score: this.score }));
        this.emit('score_achieved', this.details({ score: this.score }));
    };

    Quiz.prototype.refreshFeedbackOverflow = function (question) {
        var feedback = question.querySelector('[data-fof-feedback]');
        var scroller = question.querySelector('[data-fof-feedback-scroll]');
        var indicator = question.querySelector('[data-fof-feedback-scroll-indicator]');

        if (!feedback || !scroller || !indicator || feedback.hidden) {
            if (indicator) {
                indicator.hidden = true;
            }
            return;
        }

        feedback.classList.remove('has-overflow');
        var hasOverflow = scroller.scrollHeight > scroller.clientHeight + 2;
        feedback.classList.toggle('has-overflow', hasOverflow);

        if (!hasOverflow) {
            scroller.scrollTop = 0;
        }
        this.updateFeedbackOverflowIndicator(question);
    };

    Quiz.prototype.updateFeedbackOverflowIndicator = function (question) {
        var feedback = question.querySelector('[data-fof-feedback]');
        var scroller = question.querySelector('[data-fof-feedback-scroll]');
        var indicator = question.querySelector('[data-fof-feedback-scroll-indicator]');

        if (!feedback || !scroller || !indicator || feedback.hidden) {
            if (indicator) {
                indicator.hidden = true;
            }
            return;
        }

        var hasOverflow = feedback.classList.contains('has-overflow');
        var hasContentBelow = scroller.scrollTop + scroller.clientHeight < scroller.scrollHeight - 8;
        indicator.hidden = !(hasOverflow && hasContentBelow);
    };

    Quiz.prototype.scrollFeedbackDown = function (question) {
        var scroller = question.querySelector('[data-fof-feedback-scroll]');
        if (!scroller) {
            return;
        }

        var reducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        scroller.scrollBy({
            top: Math.max(140, scroller.clientHeight * 0.55),
            behavior: reducedMotion ? 'auto' : 'smooth'
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
