<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * XHTML+ question exporter with complete solution support.
 *
 * @package    qformat_xhtmlplus
 * @copyright  2026 Giovanni Licari
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * XHTML+ question exporter with complete metadata and solutions.
 *
 * Exports questions as static HTML with all metadata including answers,
 * feedback, scores, tags, and question types for all question types.
 *
 * @copyright  2005 Howard Miller
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qformat_xhtmlplus extends qformat_default {

    public function provide_export() {
        return true;
    }

    protected function repchar($text) {
        return $text;
    }

    /**
     * Human readable label for a question type, falling back to the raw
     * qtype name if the plugin providing it is not installed.
     */
    protected function get_question_type_label($qtype) {
        $component = 'qtype_' . $qtype;
        if (get_string_manager()->string_exists('pluginname', $component)) {
            return get_string('pluginname', $component);
        }
        return $qtype;
    }

    /**
     * Name of the category a question lives in, if it can be determined.
     */
    protected function get_question_category_name($question) {
        global $DB;
        if (empty($question->category)) {
            return '';
        }
        $name = $DB->get_field('question_categories', 'name', array('id' => $question->category));
        return $name ? format_string($name) : '';
    }

    /**
     * Full name of the user who created a question, if it can be determined.
     */
    protected function get_question_author_name($question) {
        global $DB;
        if (empty($question->createdby)) {
            return '';
        }
        $user = $DB->get_record('user', array('id' => $question->createdby),
                'id, firstname, lastname, alternatename, firstnamephonetic, lastnamephonetic, middlename');
        return $user ? fullname($user) : '';
    }

    /**
     * Question bank version number, if the question_versions table is available.
     */
    protected function get_question_version($question) {
        global $DB;
        if (empty($question->id) || !$DB->get_manager()->table_exists('question_versions')) {
            return '';
        }
        $version = $DB->get_field('question_versions', 'version', array('questionid' => $question->id));
        return ($version === false) ? '' : $version;
    }

    /**
     * Get tags for a question via the core Tag API.
     */
    protected function get_question_tags($question) {
        if (!class_exists('core_tag_tag')) {
            return array();
        }
        return core_tag_tag::get_item_tags_array('core_question', 'question', $question->id);
    }

    /**
     * Render question metadata section: identifiers, dates, authorship and tags.
     */
    protected function render_metadata($question) {
        $rows = array();
        $rows[] = array('ID', s($question->id));
        $rows[] = array(get_string('name', 'qformat_xhtmlplus'), s($question->name));
        $rows[] = array(get_string('type', 'qformat_xhtmlplus'), s($question->qtype));

        if (isset($question->defaultmark) && $question->defaultmark > 0) {
            $rows[] = array(get_string('defaultmark', 'qformat_xhtmlplus'), s($question->defaultmark));
        }

        $category = $this->get_question_category_name($question);
        if ($category !== '') {
            $rows[] = array(get_string('category', 'qformat_xhtmlplus'), s($category));
        }

        if (!empty($question->timecreated)) {
            $rows[] = array(get_string('createdon', 'qformat_xhtmlplus'), userdate($question->timecreated));
        }

        if (!empty($question->timemodified)) {
            $rows[] = array(get_string('modifiedon', 'qformat_xhtmlplus'), userdate($question->timemodified));
        }

        $author = $this->get_question_author_name($question);
        if ($author !== '') {
            $rows[] = array(get_string('author', 'qformat_xhtmlplus'), s($author));
        }

        $version = $this->get_question_version($question);
        if ($version !== '') {
            $rows[] = array(get_string('version', 'qformat_xhtmlplus'), s($version));
        }

        $output = "<div class=\"question-metadata\">\n";
        $output .= "  <div class=\"meta-grid\">\n";
        foreach ($rows as $row) {
            $output .= "    <div class=\"meta-item\"><span class=\"meta-label\">{$row[0]}</span><span class=\"meta-value\">{$row[1]}</span></div>\n";
        }
        $output .= "  </div>\n";

        $tags = $this->get_question_tags($question);
        if (!empty($tags)) {
            $output .= "  <div class=\"tag-list\">";
            foreach ($tags as $tag) {
                $output .= "<span class=\"tag-pill\">" . s($tag) . "</span>";
            }
            $output .= "</div>\n";
        }

        $output .= "</div>\n";
        return $output;
    }

    /**
     * Render general feedback.
     */
    protected function render_general_feedback($question) {
        if (empty($question->generalfeedback)) {
            return '';
        }

        $text = question_rewrite_question_preview_urls($question->generalfeedback, $question->id,
                $question->contextid, 'question', 'generalfeedback', $question->id,
                $question->contextid, 'qformat_xhtmlplus');

        $output = "<div class=\"general-feedback\">\n";
        $output .= "  <strong class=\"feedback-title\">" . get_string('generalfeedback', 'question') . "</strong>\n";
        $output .= "  <div class=\"feedback-content\">\n";
        $output .= "    " . format_text($text, $question->generalfeedbackformat, array('noclean' => true)) . "\n";
        $output .= "  </div>\n";
        $output .= "</div>\n";
        return $output;
    }

    /**
     * Render one answer row: a marker (shown only when correct) plus its text.
     *
     * @param string $text answer text.
     * @param bool $correct whether to mark this row as the/a correct answer.
     * @param bool $rawhtml if true, $text is inserted as-is instead of being escaped
     *        with s() (used when the caller has already built safe markup).
     */
    protected function render_answer_row($text, $correct, $rawhtml = false) {
        $class = $correct ? ' correct' : '';
        $marker = $correct ? '&#10003;' : '';
        $content = $rawhtml ? $text : s($text);
        return "    <div class=\"answer-row{$class}\"><span class=\"answer-marker\">{$marker}</span>"
                . "<span class=\"answer-text\">{$content}</span></div>\n";
    }

    protected function writequestion($question) {
        global $OUTPUT;

        if ($question->qtype == 'category') {
            return '';
        }

        $id = $question->id;
        $expout = "<!-- question: {$id}  name: {$question->name}  type: {$question->qtype} -->\n";
        $expout .= "<div class=\"question\" id=\"q-{$id}\">\n";

        $expout .= "  <div class=\"question-header\">\n";
        $expout .= "    <h3 class=\"question-title\">" . s($question->name) . "</h3>\n";
        $expout .= "    <span class=\"type-badge\">" . s($this->get_question_type_label($question->qtype)) . "</span>\n";
        $expout .= "  </div>\n";

        // Metadata section
        $expout .= $this->render_metadata($question);

        // Drag and drop question types embed [[N]] placeholders (or image drop
        // zones) directly in the question text, which can look cryptic on its
        // own, so name the interaction style right above the question text.
        if (in_array($question->qtype, array('ddimageortext', 'ddmarker', 'ddwtos'), true)) {
            $expout .= "  <p class=\"question-info\">" . s($this->get_question_type_label($question->qtype)) . "</p>\n";
        }

        // Question text
        $text = question_rewrite_question_preview_urls($question->questiontext, $id,
                $question->contextid, 'question', 'questiontext', $id,
                $question->contextid, 'qformat_xhtmlplus');
        $expout .= "  <div class=\"question-text\">" . format_text($text,
                $question->questiontextformat, array('noclean' => true)) . "</div>\n";

        // Selection depends on question type.
        switch($question->qtype) {
            case 'truefalse':
                $expout .= $this->write_truefalse($question);
                break;
            case 'multichoice':
                $expout .= $this->write_multichoice($question);
                break;
            case 'shortanswer':
                $expout .= $this->write_shortanswer($question);
                break;
            case 'numerical':
                $expout .= $this->write_numerical($question);
                break;
            case 'match':
                $expout .= $this->write_match($question);
                break;
            case 'essay':
                $expout .= $this->write_essay($question);
                break;
            case 'multianswer':
                $expout .= $this->write_multianswer($question);
                break;
            case 'calculated':
            case 'calculatedmulti':
            case 'calculatedsimple':
                $expout .= $this->write_calculated($question);
                break;
            case 'ddimageortext':
            case 'ddmarker':
            case 'ddwtos':
                $expout .= $this->write_dragdrop($question);
                break;
            case 'gapselect':
                $expout .= $this->write_gapselect($question);
                break;
            case 'description':
                break;
            default:
                $expout .= "  <div class=\"unsupported-type\">\n";
                $expout .= "    <p>" . get_string('exportnotimplemented', 'qformat_xhtmlplus', $question->qtype) . "</p>\n";
                $expout .= "  </div>\n";
        }

        // General feedback
        $expout .= $this->render_general_feedback($question);

        $expout .= "</div>\n\n";
        return $expout;
    }

    /**
     * Write true/false question.
     */
    protected function write_truefalse($question) {
        $sttrue = get_string('true', 'qtype_truefalse');
        $stfalse = get_string('false', 'qtype_truefalse');
        $correct_tf = '';

        foreach ($question->options->answers as $answer) {
            if ($answer->fraction > 0) {
                $correct_tf = trim(strtolower($answer->answer));
            }
        }

        $true_correct  = ($correct_tf == trim(strtolower($sttrue)));
        $false_correct = ($correct_tf == trim(strtolower($stfalse)));

        $expout = "<div class=\"question-content truefalse\">\n";
        $expout .= "  <div class=\"answer-list\">\n";
        $expout .= $this->render_answer_row($sttrue, $true_correct);
        $expout .= $this->render_answer_row($stfalse, $false_correct);
        $expout .= "  </div>\n";

        foreach ($question->options->answers as $answer) {
            if (!empty($answer->feedback)) {
                $expout .= "  <div class=\"answer-feedback\">\n";
                $expout .= "    <strong>" . ($answer->fraction > 0 ? $sttrue : $stfalse) . ":</strong> ";
                $expout .= format_text($answer->feedback, $answer->feedbackformat, array('noclean' => true)) . "\n";
                $expout .= "  </div>\n";
            }
        }

        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Write multichoice question.
     */
    protected function write_multichoice($question) {
        $expout = "<div class=\"question-content multichoice\">\n";

        if (!$question->options->single) {
            $expout .= "  <p class=\"question-info\">" . get_string('multipleanswers', 'qformat_xhtmlplus') . "</p>\n";
        }

        $expout .= "  <div class=\"answer-list\">\n";
        foreach ($question->options->answers as $answer) {
            $answertext = $this->repchar($answer->answer);
            $iscorrect = ($answer->fraction > 0);
            $expout .= $this->render_answer_row($answertext, $iscorrect);

            if (!empty($answer->feedback)) {
                $expout .= "    <div class=\"answer-feedback\">" . format_text($answer->feedback, $answer->feedbackformat, array('noclean' => true)) . "</div>\n";
            }
        }
        $expout .= "  </div>\n";
        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Write short answer question.
     */
    protected function write_shortanswer($question) {
        $expout = "<div class=\"question-content shortanswer\">\n";
        $expout .= "  <div class=\"answer-list\">\n";

        foreach ($question->options->answers as $answer) {
            if ($answer->fraction > 0) {
                $expout .= $this->render_answer_row($answer->answer, true);
                if (!empty($answer->feedback)) {
                    $expout .= "    <div class=\"answer-feedback\">" . format_text($answer->feedback, $answer->feedbackformat, array('noclean' => true)) . "</div>\n";
                }
            }
        }

        $expout .= "  </div>\n";
        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Write numerical question.
     */
    protected function write_numerical($question) {
        $expout = "<div class=\"question-content numerical\">\n";
        $expout .= "  <div class=\"answer-list\">\n";

        foreach ($question->options->answers as $answer) {
            if ($answer->fraction > 0) {
                $tol = isset($answer->tolerance) && $answer->tolerance > 0
                     ? ' &plusmn;' . s($answer->tolerance) : '';
                $expout .= $this->render_answer_row(s($answer->answer) . $tol, true, true);
                if (!empty($answer->feedback)) {
                    $expout .= "    <div class=\"answer-feedback\">" . format_text($answer->feedback, $answer->feedbackformat, array('noclean' => true)) . "</div>\n";
                }
            }
        }

        $expout .= "  </div>\n";
        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Write match question.
     */
    protected function write_match($question) {
        $expout = "<div class=\"question-content match\">\n";
        $expout .= "  <div class=\"answer-list\">\n";

        foreach ($question->options->subquestions as $subquestion) {
            $questiontext = $this->repchar($subquestion->questiontext);
            if ($questiontext == '') {
                continue;
            }
            $answertext = $this->repchar($subquestion->answertext);
            $pair = '<strong>' . s($questiontext) . '</strong> &rarr; ' . s($answertext);
            $expout .= $this->render_answer_row($pair, true, true);
        }

        $expout .= "  </div>\n";
        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Write essay question.
     */
    protected function write_essay($question) {
        $expout = "<div class=\"question-content essay\">\n";

        if (isset($question->options)) {
            $rows = array();
            if (isset($question->options->responseformat)) {
                $rows[] = array(get_string('responseformat', 'qtype_essay'), s($question->options->responseformat));
            }
            if (isset($question->options->responserequired)) {
                $rows[] = array(get_string('responserequired', 'qtype_essay'),
                        $question->options->responserequired ? get_string('yes') : get_string('no'));
            }
            if (!empty($question->options->minwordlimit)) {
                $rows[] = array(get_string('minwordlimit', 'qtype_essay'), s($question->options->minwordlimit));
            }
            if (!empty($question->options->maxwordlimit)) {
                $rows[] = array(get_string('maxwordlimit', 'qtype_essay'), s($question->options->maxwordlimit));
            }
            if (isset($question->options->attachments)) {
                $rows[] = array(get_string('attachments', 'qformat_xhtmlplus'), s($question->options->attachments));
            }

            if (!empty($rows)) {
                $expout .= "  <div class=\"meta-grid\">\n";
                foreach ($rows as $row) {
                    $expout .= "    <div class=\"meta-item\"><span class=\"meta-label\">{$row[0]}</span><span class=\"meta-value\">{$row[1]}</span></div>\n";
                }
                $expout .= "  </div>\n";
            }

            if (!empty($question->options->responsetemplate)) {
                $expout .= "  <div class=\"question-info\"><strong>" . get_string('responsetemplate', 'qtype_essay') . "</strong></div>\n";
                $expout .= "  <div class=\"question-text\">" . format_text($question->options->responsetemplate,
                        $question->options->responsetemplateformat, array('noclean' => true)) . "</div>\n";
            }

            if (!empty($question->options->graderinfo)) {
                $graderinfo = question_rewrite_question_preview_urls($question->options->graderinfo, $question->id,
                        $question->contextid, 'qtype_essay', 'graderinfo', $question->id,
                        $question->contextid, 'qformat_xhtmlplus');
                $expout .= "  <div class=\"question-info\"><strong>" . get_string('graderinfo', 'qtype_essay') . "</strong></div>\n";
                $expout .= "  <div class=\"question-text\">" . format_text($graderinfo,
                        $question->options->graderinfoformat, array('noclean' => true)) . "</div>\n";
            }
        }

        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Write multianswer (cloze) question.
     */
    protected function write_multianswer($question) {
        $expout = "<div class=\"question-content multianswer\">\n";

        if (isset($question->options->questions) && is_array($question->options->questions)) {
            $reconstructed = $question->questiontext;
            $haveall = true;
            $spacerows = '';
            $index = 0;

            foreach ($question->options->questions as $subq) {
                if (!$subq || !isset($subq->qtype) || $subq->qtype === 'subquestion_replacement'
                        || !isset($subq->options->answers)) {
                    continue;
                }
                $index++;

                $accepted = $this->get_multianswer_accepted_answers($subq);
                if (empty($accepted)) {
                    $haveall = false;
                    continue;
                }

                $placeholder = '{#' . $index . '}';
                if (strpos($reconstructed, $placeholder) !== false) {
                    $reconstructed = str_replace($placeholder, '<strong>' . $accepted[0]['text'] . '</strong>', $reconstructed);
                } else {
                    $haveall = false;
                }

                foreach ($accepted as $answer) {
                    $label = '<strong>' . s(get_string('space', 'qformat_xhtmlplus', $index)) . ':</strong> ' . $answer['text'];
                    $spacerows .= $this->render_answer_row($label, true, true);
                    if ($answer['feedback'] !== '') {
                        $spacerows .= "    <div class=\"answer-feedback\">" . $answer['feedback'] . "</div>\n";
                    }
                }
            }

            if ($haveall && $index > 0) {
                $expout .= $this->render_reconstructed_text($question, $reconstructed);
            }

            if ($spacerows !== '') {
                $expout .= "  <div class=\"answer-list\">\n" . $spacerows . "  </div>\n";
            }
        }

        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * All accepted (fraction > 0) answers for one embedded Cloze sub-question,
     * highest fraction first. A sub-question is a full question object of its
     * own qtype (numerical, shortanswer or multichoice - confirmed against
     * Moodle core qtype_multianswer, which loads each one via the real
     * get_question_options() of that qtype), so it can define more than one
     * accepted answer (partial-credit alternatives, or several correct
     * checkboxes for a multi-response gap) and, for numerical sub-questions,
     * a tolerance - both are reflected here instead of only the first match.
     *
     * @return array of ['fraction' => float, 'text' => pre-escaped safe HTML, 'feedback' => safe HTML or ''].
     */
    protected function get_multianswer_accepted_answers($subq) {
        $answers = array();
        foreach ($subq->options->answers as $answer) {
            if ($answer->fraction <= 0) {
                continue;
            }
            $text = s($answer->answer);
            if ($subq->qtype === 'numerical' && isset($answer->tolerance) && $answer->tolerance > 0) {
                $text .= ' &plusmn;' . s($answer->tolerance);
            }
            $feedback = '';
            if (!empty($answer->feedback)) {
                $feedback = format_text($answer->feedback, $answer->feedbackformat, array('noclean' => true));
            }
            $answers[] = array('fraction' => (float) $answer->fraction, 'text' => $text, 'feedback' => $feedback);
        }
        usort($answers, function($a, $b) {
            return $b['fraction'] <=> $a['fraction'];
        });
        return $answers;
    }

    /**
     * Write calculated question.
     */
    protected function write_calculated($question) {
        $expout = "<div class=\"question-content calculated\">\n";
        $expout .= $this->render_calculated_sample($question);
        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Best-effort extraction of one dataset item value per variable used by a
     * calculated question.
     */
    protected function get_calculated_dataset_values($question) {
        $values = array();

        if (empty($question->options->datasets)) {
            return $values;
        }

        foreach ($question->options->datasets as $dataset) {
            if (!isset($dataset->name)) {
                continue;
            }
            $item = null;
            if (isset($dataset->items) && is_array($dataset->items)) {
                $item = reset($dataset->items);
            }
            if (is_object($item) && isset($item->value)) {
                $values[$dataset->name] = $item->value;
            } else if (is_scalar($item)) {
                $values[$dataset->name] = $item;
            }
        }

        return $values;
    }

    /**
     * Render a worked example of a calculated question: the question text and
     * the first correct-answer formula, with one set of dataset values
     * substituted in, plus the evaluated result. The formula is evaluated with
     * a small hand-written arithmetic parser (see evaluate_arithmetic_expression()),
     * never with eval(), so nothing but basic arithmetic on the substituted
     * numeric values can ever run. Silently renders nothing if the dataset or
     * the formula cannot be safely substituted/evaluated (e.g. it uses
     * functions like sqrt() or sin() that this parser does not support).
     */
    protected function render_calculated_sample($question) {
        $datasetvalues = $this->get_calculated_dataset_values($question);
        if (empty($datasetvalues) || !isset($question->options->answers)) {
            return '';
        }

        $sampletext = $question->questiontext;
        foreach ($datasetvalues as $name => $value) {
            $sampletext = str_replace('{' . $name . '}', $value, $sampletext);
        }

        $answerrow = '';
        $feedbackhtml = '';
        foreach ($question->options->answers as $answer) {
            if ($answer->fraction <= 0) {
                continue;
            }
            $formula = $answer->answer;
            foreach ($datasetvalues as $name => $value) {
                $formula = str_replace('{' . $name . '}', $value, $formula);
            }
            $result = $this->evaluate_arithmetic_expression($formula);
            if ($result !== null) {
                $answerrow = $this->render_answer_row($this->format_calculated_result($result), true);
                if (!empty($answer->feedback)) {
                    $feedbackhtml = "    <div class=\"answer-feedback\">" . format_text($answer->feedback, $answer->feedbackformat, array('noclean' => true)) . "</div>\n";
                }
            }
            break;
        }

        if ($answerrow === '') {
            return '';
        }

        $output = $this->render_reconstructed_text($question, $sampletext);
        $output .= "  <div class=\"answer-list\">\n" . $answerrow . $feedbackhtml . "  </div>\n";
        return $output;
    }

    /**
     * Format a computed numeric result for display: whole numbers show with no
     * decimal point, otherwise trailing zeros are trimmed.
     */
    protected function format_calculated_result($value) {
        if (floor($value) == $value) {
            return (string) (int) round($value);
        }
        $formatted = rtrim(rtrim(sprintf('%.10f', $value), '0'), '.');
        return ($formatted === '' || $formatted === '-') ? '0' : $formatted;
    }

    /**
     * Safely evaluate a simple arithmetic expression (+, -, *, /, ^, parentheses,
     * decimal numbers) without eval(), so only arithmetic on the substituted
     * dataset values can ever run.
     *
     * @return float|null the result, or null if the expression contains
     *         anything this parser does not recognise (e.g. a function call, or
     *         a placeholder that was not substituted).
     */
    protected function evaluate_arithmetic_expression($expr) {
        $expr = trim($expr);
        if ($expr === '' || !preg_match('/^[0-9+\-*\/^().\s]+$/', $expr)) {
            return null;
        }
        if (!preg_match_all('/\d+(?:\.\d+)?|[+\-*\/^()]/', $expr, $matches)) {
            return null;
        }
        $tokens = $matches[0];
        if (strlen(preg_replace('/\s+/', '', $expr)) !== strlen(implode('', $tokens))) {
            return null;
        }

        $pos = 0;
        $peek = function() use (&$tokens, &$pos) {
            return $pos < count($tokens) ? $tokens[$pos] : null;
        };
        $next = function() use (&$tokens, &$pos) {
            return $tokens[$pos++];
        };

        $parseexpression = null;
        $parseterm = null;
        $parsefactor = null;
        $parseprimary = null;

        $parseprimary = function() use (&$next, &$peek, &$parseprimary, &$parseexpression) {
            $tok = $peek();
            if ($tok === null) {
                throw new Exception('unexpected end of expression');
            }
            if ($tok === '(') {
                $next();
                $value = $parseexpression();
                if ($peek() !== ')') {
                    throw new Exception('expected closing parenthesis');
                }
                $next();
                return $value;
            }
            if ($tok === '-') {
                $next();
                return -$parseprimary();
            }
            if ($tok === '+') {
                $next();
                return $parseprimary();
            }
            if (is_numeric($tok)) {
                $next();
                return (float) $tok;
            }
            throw new Exception('unexpected token');
        };

        $parsefactor = function() use (&$peek, &$next, &$parseprimary) {
            $value = $parseprimary();
            while ($peek() === '^') {
                $next();
                $value = pow($value, $parseprimary());
            }
            return $value;
        };

        $parseterm = function() use (&$peek, &$next, &$parsefactor) {
            $value = $parsefactor();
            while ($peek() === '*' || $peek() === '/') {
                $op = $next();
                $rhs = $parsefactor();
                if ($op === '*') {
                    $value *= $rhs;
                } else {
                    if ((float) $rhs === 0.0) {
                        throw new Exception('division by zero');
                    }
                    $value /= $rhs;
                }
            }
            return $value;
        };

        $parseexpression = function() use (&$peek, &$next, &$parseterm) {
            $value = $parseterm();
            while ($peek() === '+' || $peek() === '-') {
                $op = $next();
                $rhs = $parseterm();
                $value = ($op === '+') ? $value + $rhs : $value - $rhs;
            }
            return $value;
        };

        try {
            $result = $parseexpression();
            if ($pos !== count($tokens)) {
                return null;
            }
            return $result;
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Render a copy of the question text with placeholders already substituted
     * by the caller, labelled as a worked example, reusing the same URL
     * rewriting and formatting as the real question text.
     */
    protected function render_reconstructed_text($question, $text) {
        $rewritten = question_rewrite_question_preview_urls($text, $question->id,
                $question->contextid, 'question', 'questiontext', $question->id,
                $question->contextid, 'qformat_xhtmlplus');

        $output = "  <div class=\"question-info\"><strong>" . get_string('sampleinstance', 'qformat_xhtmlplus') . "</strong></div>\n";
        $output .= "  <div class=\"question-text\">" . format_text($rewritten, $question->questiontextformat, array('noclean' => true)) . "</div>\n";
        return $output;
    }

    /**
     * Write drag and drop question.
     */
    protected function write_dragdrop($question) {
        $expout = "<div class=\"question-content dragdrop\">\n";

        // Only ddwtos shares qtype_gapselect_base with gapselect (confirmed against
        // Moodle core: qtype_ddwtos extends qtype_gapselect_base). ddmarker and
        // ddimageortext extend a different, image-coordinate based class and have
        // no [[N]] placeholders in their question text, so correctness cannot be
        // determined the same way for them.
        $correctids = array();
        if ($question->qtype === 'ddwtos') {
            $expout .= $this->render_gap_sample($question);
            foreach ($this->get_gap_answers_by_place($question) as $answer) {
                $correctids[$answer->id] = true;
            }
        }

        if (isset($question->options->answers)) {
            $expout .= "  <p class=\"question-info\">" . get_string('availableoptions', 'qformat_xhtmlplus') . "</p>\n";
            $expout .= "  <div class=\"answer-list\">\n";
            foreach ($question->options->answers as $answer) {
                $expout .= $this->render_answer_row($answer->answer, isset($correctids[$answer->id]));
            }
            $expout .= "  </div>\n";
        }

        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * Write gap select question.
     */
    protected function write_gapselect($question) {
        $expout = "<div class=\"question-content gapselect\">\n";
        $expout .= "  <p class=\"question-info\">" . s($this->get_question_type_label($question->qtype)) . "</p>\n";

        $expout .= $this->render_gap_sample($question);

        if (isset($question->options->answers)) {
            $correctids = array();
            foreach ($this->get_gap_answers_by_place($question) as $answer) {
                $correctids[$answer->id] = true;
            }

            $expout .= "  <p class=\"question-info\">" . get_string('availableoptions', 'qformat_xhtmlplus') . "</p>\n";
            $expout .= "  <div class=\"answer-list\">\n";
            foreach ($question->options->answers as $answer) {
                $expout .= $this->render_answer_row($answer->answer, isset($correctids[$answer->id]));
            }
            $expout .= "  </div>\n";
        }

        $expout .= "</div>\n";
        return $expout;
    }

    /**
     * For qtype_gapselect_base question types (gapselect, ddwtos): the number
     * inside each [[N]] placeholder in the question text is a 1-based index
     * into $question->options->answers ordered by id, identifying the correct
     * choice for that gap. Confirmed against Moodle core
     * (qtype_gapselect_base::initialise_question_instance() and
     * question_type::get_question_options(), which loads answers "ORDER BY id").
     *
     * @return array place number => the answer object correct for that place.
     */
    protected function get_gap_answers_by_place($question) {
        $places = array();
        if (empty($question->options->answers)) {
            return $places;
        }

        $answers = array_values($question->options->answers);
        if (preg_match_all('/\[\[(\d+)\]\]/', $question->questiontext, $matches)) {
            foreach ($matches[1] as $place) {
                $index = ((int) $place) - 1;
                if (isset($answers[$index])) {
                    $places[$place] = $answers[$index];
                }
            }
        }
        return $places;
    }

    /**
     * Render a worked example for a gapselect/ddwtos question: the question
     * text with every [[N]] placeholder replaced by its correct choice.
     */
    protected function render_gap_sample($question) {
        $places = $this->get_gap_answers_by_place($question);
        if (empty($places)) {
            return '';
        }

        $reconstructed = $question->questiontext;
        foreach ($places as $place => $answer) {
            $reconstructed = str_replace('[[' . $place . ']]', '<strong>' . s($answer->answer) . '</strong>', $reconstructed);
        }

        return $this->render_reconstructed_text($question, $reconstructed);
    }

    protected function presave_process($content) {
        global $CFG;

        $cssfile = "{$CFG->dirroot}/question/format/xhtmlplus/xhtml.css";
        $css = '';
        if (file_exists($cssfile)) {
            $csslines = file($cssfile);
            $css = implode(' ', $csslines);
        }

        $xp = "<!DOCTYPE html>\n";
        $xp .= "<html xmlns=\"http://www.w3.org/1999/xhtml\" lang=\"en\">\n";
        $xp .= "<head>\n";
        $xp .= "  <meta charset=\"UTF-8\" />\n";
        $xp .= "  <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\" />\n";
        $xp .= "  <title>" . get_string('exportedquestions', 'qformat_xhtmlplus') . "</title>\n";
        $xp .= "  <style type=\"text/css\">\n";
        $xp .= "    /* Moodle XHTML+ Export Styles */\n";
        $xp .= $css;
        $xp .= "  </style>\n";
        $xp .= "</head>\n";
        $xp .= "<body>\n";
        $xp .= "  <div class=\"questions-container\">\n";
        $xp .= "    <h1>" . get_string('exportedquestions', 'qformat_xhtmlplus') . "</h1>\n";
        $xp .= "    <p class=\"export-date\">Exported: " . date('Y-m-d H:i:s') . "</p>\n";
        $xp .= $content;
        $xp .= "  </div>\n";
        $xp .= "</body>\n";
        $xp .= "</html>\n";

        return $xp;
    }

    public function export_file_extension() {
        return '.html';
    }
}
