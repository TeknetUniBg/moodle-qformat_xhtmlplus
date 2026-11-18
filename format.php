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
 * @copyright  2026 Giovanni Licari, Alessandro Lorenzi, Claudio Codice
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

    /** Images up to this size (in bytes) are embedded in the exported file as data URIs. */
    const MAX_EMBEDDED_IMAGE_BYTES = 1048576;

    /** Extensions of linked files shown as audio. */
    const AUDIO_EXTENSIONS = ['mp3', 'm4a', 'ogg', 'oga', 'wav', 'aac', 'flac', 'opus'];

    /** Extensions of linked files shown as video. */
    const VIDEO_EXTENSIONS = ['mp4', 'm4v', 'webm', 'ogv', 'mov', 'avi', 'mkv', 'mpeg', 'mpg', '3gp'];

    /** Hosts whose links and embeds are shown as external videos. */
    const VIDEO_HOSTS = ['youtube.com', 'youtu.be', 'youtube-nocookie.com', 'vimeo.com'];

    /** Icon shown before each kind of media reference. */
    const MEDIA_ICONS = [
        'audio' => '🔊',
        'video' => '🎬',
        'externalvideo' => '🎬',
        'embedded' => '🔗',
    ];

    public function provide_export() {
        return true;
    }

    /** @var int number of the question being written, for the index. */
    protected $questionnumber = 0;

    /** @var array index entries: category path => list of [anchor, number, name, type label]. */
    protected $index = [];

    /** @var array cache of category paths by category id. */
    protected $categorypaths = [];

    /** @var array number of questions by type label. */
    protected $typecounts = [];

    /**
     * Shortcut for this plugin's strings.
     *
     * @param string $key
     * @param mixed $a
     * @return string
     */
    protected function str($key, $a = null) {
        return get_string($key, 'qformat_xhtmlplus', $a);
    }

    /**
     * Readable name of a question type.
     *
     * @param string $qtype
     * @return string
     */
    protected function qtype_label($qtype) {
        if (get_string_manager()->string_exists('pluginname', 'qtype_' . $qtype)) {
            return get_string('pluginname', 'qtype_' . $qtype);
        }
        return $qtype;
    }

    /**
     * Number without useless decimals (1.0000000 -> 1).
     *
     * @param float $value
     * @return string
     */
    protected function number($value) {
        return format_float((float) $value, 4, true, true);
    }

    /**
     * Grade of an answer as a percentage badge.
     *
     * @param float $fraction
     * @return string HTML
     */
    protected function fraction_badge($fraction) {
        $fraction = (float) $fraction;
        if ($fraction >= 0.9999999) {
            $class = 'correct';
        } else if ($fraction > 0) {
            $class = 'partial';
        } else if ($fraction < 0) {
            $class = 'penalty';
        } else {
            $class = 'wrong';
        }
        return '<span class="grade grade-' . $class . '">' . $this->number($fraction * 100) . '%</span>';
    }

    /**
     * CSS class of a row for an answer grade.
     *
     * @param float $fraction
     * @return string
     */
    protected function fraction_class($fraction) {
        $fraction = (float) $fraction;
        if ($fraction >= 0.9999999) {
            return 'is-correct';
        }
        return $fraction > 0 ? 'is-partial' : 'is-wrong';
    }

    /**
     * Path of a question category, without the hidden "top" category.
     *
     * @param int $categoryid
     * @return string plain text
     */
    protected function category_breadcrumb($categoryid) {
        global $DB;
        if (!$categoryid) {
            return '';
        }
        if (!isset($this->categorypaths[$categoryid])) {
            $names = [];
            $id = $categoryid;
            for ($guard = 0; $id && $guard < 50; $guard++) {
                $category = $DB->get_record('question_categories', ['id' => $id], 'id, name, parent, contextid');
                if (!$category) {
                    break;
                }
                if (!($category->parent == 0 && $category->name === 'top')) {
                    array_unshift($names, format_string($category->name, true,
                            ['context' => context::instance_by_id($category->contextid)]));
                }
                $id = $category->parent;
            }
            $this->categorypaths[$categoryid] = implode(' › ', $names);
        }
        return $this->categorypaths[$categoryid];
    }

    /**
     * Category id of a question (export from the interface or from the CLI script).
     *
     * @param stdClass $question
     * @return int
     */
    protected function question_category_id($question) {
        global $DB;
        if (!empty($question->category)) {
            return (int) $question->category;
        }
        return (int) $DB->get_field_sql('SELECT qbe.questioncategoryid
                                           FROM {question_versions} qv
                                           JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
                                          WHERE qv.questionid = ?', [$question->id]);
    }

    /**
     * Header of a question: category path, number, name, type and points.
     *
     * @param stdClass $question
     * @param string $path category path
     * @return string HTML
     */
    protected function render_question_header($question, $path) {
        $output = "<header class=\"q-head\">\n";
        if ($path !== '') {
            $output .= '  <div class="q-path">' . s($path) . "</div>\n";
        }
        $output .= '  <h2 class="q-title"><span class="q-num">' . $this->questionnumber . '</span> '
                . s($question->name) . "</h2>\n";
        $output .= '  <div class="q-badges"><span class="badge badge-type">' . s($this->qtype_label($question->qtype))
                . '</span>';
        if (isset($question->defaultmark) && $question->defaultmark > 0 && $question->qtype !== 'description') {
            $mark = (float) $question->defaultmark;
            $output .= '<span class="badge">' . $this->str($mark == 1 ? 'pointone' : 'points', $this->number($mark))
                    . '</span>';
        }
        // Penalty for each wrong try (interactive / adaptive behaviours).
        if (!empty($question->penalty) && (float) $question->penalty > 0
                && !in_array($question->qtype, ['description', 'essay'])) {
            $output .= '<span class="badge badge-penalty">'
                    . s($this->str('penalty', format_float($question->penalty * 100, 1, true, true) . '%')) . '</span>';
        }
        $output .= "</div>\n</header>\n";
        return $output;
    }

    /**
     * General feedback block.
     *
     * @param stdClass $question
     * @return string HTML
     */
    protected function render_general_feedback($question) {
        if (trim(strip_tags((string) $question->generalfeedback, '<img><audio><video><iframe>')) === '') {
            return '';
        }
        $html = $this->render_text($question->generalfeedback, $question->generalfeedbackformat,
                $question, 'question', 'generalfeedback', $question->id);
        if ($question->qtype === 'stack') {
            $html = $this->stack_cas_markers($html);
        }
        return $this->section(get_string('generalfeedback', 'question'), $html, 'q-feedback');
    }

    /**
     * Show STACK CAS expressions ({@x@}, {#x#}) as ⟨x⟩: readable in plain text and inside TeX formulas,
     * where MathJax would otherwise turn "@x@" into italic letters.
     *
     * @param string $html
     * @return string
     */
    protected function stack_cas_markers($html) {
        return preg_replace('~\{([@#])(.+?)\1\}~s', '⟨$2⟩', $html);
    }

    /**
     * Combined feedback (correct / partially correct / incorrect), when the type has it.
     *
     * @param stdClass $question
     * @return string HTML
     */
    protected function render_combined_feedback($question) {
        if (!isset($question->options)) {
            return '';
        }
        $rows = '';
        foreach (['correctfeedback', 'partiallycorrectfeedback', 'incorrectfeedback'] as $field) {
            $text = $question->options->$field ?? '';
            if (trim(strip_tags((string) $text, '<img>')) === '') {
                continue;
            }
            $format = $question->options->{$field . 'format'} ?? FORMAT_HTML;
            $rows .= '<tr><th>' . s($this->str($field)) . '</th><td>'
                    . $this->render_text($text, $format, $question, 'question', $field, $question->id) . "</td></tr>\n";
        }
        if ($rows === '') {
            return '';
        }
        return $this->section($this->str('combinedfeedback'), "<table class=\"kv\">\n$rows</table>", 'q-feedback');
    }

    /**
     * Hints for the interactive behaviour.
     *
     * @param stdClass $question
     * @return string HTML
     */
    protected function render_hints($question) {
        if (empty($question->hints)) {
            return '';
        }
        $items = '';
        foreach ($question->hints as $hint) {
            if (trim(strip_tags((string) $hint->hint, '<img>')) === '') {
                continue;
            }
            $items .= '<li>' . $this->render_text($hint->hint, $hint->hintformat, $question, 'question', 'hint', $hint->id)
                    . "</li>\n";
        }
        if ($items === '') {
            return '';
        }
        return $this->section($this->str('hints'), "<ol class=\"hints\">\n$items</ol>", 'q-feedback');
    }

    /**
     * A titled block inside a question.
     *
     * @param string $title plain text
     * @param string $content HTML
     * @param string $class extra CSS class
     * @return string HTML
     */
    protected function section($title, $content, $class = '') {
        return '<div class="q-section ' . $class . '"><div class="q-section-title">' . s($title) . "</div>\n"
                . $content . "\n</div>\n";
    }

    /**
     * Format a question text field for the static export.
     *
     * Images stored in Moodle are embedded as data URIs (up to MAX_EMBEDDED_IMAGE_BYTES),
     * audio/video become a visible reference with a native player, external videos and
     * embeds become plain links. All other Moodle files keep a link to the server.
     *
     * @param string|null $text the raw text, which may contain @@PLUGINFILE@@ references.
     * @param int $format the text format.
     * @param stdClass $question the question being exported (gives context and id).
     * @param string $component the component that owns the files of this text.
     * @param string $filearea the file area of this text.
     * @param int $itemid the item id of this text's files.
     * @return string HTML ready for the export.
     */
    protected function render_text($text, $format, $question, $component, $filearea, $itemid) {
        if ($text === null || $text === '') {
            return '';
        }

        $context = context::instance_by_id($question->contextid);

        // Convert to HTML first, filters are applied at the end without the media plugin.
        $html = format_text($text, $format, ['noclean' => true, 'filter' => false, 'context' => $context]);

        $html = $this->process_files_and_media($html, $question->contextid, $component, $filearea, $itemid);

        // Whatever is still a Moodle file (large images, audio, video, documents) links to the server.
        $html = question_rewrite_question_preview_urls($html, $question->id, $question->contextid,
                $component, $filearea, $itemid, $question->contextid, 'qformat_xhtmlplus');

        // The media plugin turns links into players that need Moodle's JavaScript: skip it.
        return $this->text_filter_manager()->filter_text($html, $context,
                ['originalformat' => $format, 'noclean' => true], ['mediaplugin']);
    }

    /**
     * Return the filter manager (namespaced from Moodle 4.5).
     *
     * @return object
     */
    protected function text_filter_manager() {
        if (class_exists('\core_filters\filter_manager')) {
            return \core_filters\filter_manager::instance();
        }
        return filter_manager::instance();
    }

    /**
     * Embed images and replace audio, video and embeds with static references.
     *
     * @param string $html HTML that may still contain @@PLUGINFILE@@ references.
     * @param int $contextid context of the files.
     * @param string $component component of the files.
     * @param string $filearea file area of the files.
     * @param int $itemid item id of the files.
     * @return string the processed HTML.
     */
    protected function process_files_and_media($html, $contextid, $component, $filearea, $itemid) {
        if (stripos($html, '@@PLUGINFILE@@') === false
                && !preg_match('~<(audio|video|iframe)\b|<a\s[^>]*href\s*=\s*["\']?https?://~i', $html)) {
            return $html;
        }

        $doc = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $doc->loadHTML('<?xml encoding="UTF-8"?><div id="xhtmlplus-root">' . $html . '</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($doc);
        $root = $xpath->query('//div[@id="xhtmlplus-root"]')->item(0);
        if (!$root) {
            return $html;
        }

        $files = [$contextid, $component, $filearea, $itemid];
        $changed = false;

        // Images stored in Moodle: embedded so that the file works offline and in print.
        foreach (iterator_to_array($xpath->query('.//img[@src]', $root)) as $img) {
            $src = $img->getAttribute('src');
            if (strpos($src, '@@PLUGINFILE@@') !== 0) {
                continue;
            }
            $file = $this->find_stored_file($src, $files);
            if (!$file || strpos($file->get_mimetype(), 'image/') !== 0
                    || $file->get_filesize() > self::MAX_EMBEDDED_IMAGE_BYTES) {
                continue;
            }
            try {
                $content = @$file->get_content();
            } catch (Throwable $e) {
                $content = '';
            }
            if ($content === false || $content === '') {
                // File missing from storage: keep the link to the server instead of an empty image.
                continue;
            }
            $img->setAttribute('src', 'data:' . $file->get_mimetype() . ';base64,' . base64_encode($content));
            $img->removeAttribute('srcset');
            $changed = true;
        }

        // Audio and video tags: reference with file name plus a native player.
        foreach (iterator_to_array($xpath->query('.//audio | .//video', $root)) as $media) {
            $sources = [];
            if ($media->hasAttribute('src')) {
                $sources[] = $media->getAttribute('src');
            }
            foreach ($media->getElementsByTagName('source') as $source) {
                $sources[] = $source->getAttribute('src');
            }
            $sources = array_values(array_unique(array_filter($sources)));
            if (!$sources || !$media->parentNode) {
                continue;
            }
            $box = $this->build_media_reference($doc, strtolower($media->nodeName), $sources, $files);
            $media->parentNode->replaceChild($box, $media);
            $changed = true;
        }

        // Embedded frames (YouTube, Vimeo, other sites): plain link.
        foreach (iterator_to_array($xpath->query('.//iframe[@src]', $root)) as $iframe) {
            $src = $iframe->getAttribute('src');
            $kind = $this->get_link_media_kind($src, $files) ?? 'embedded';
            $box = $this->build_media_reference($doc, $kind, [$src], $files);
            $iframe->parentNode->replaceChild($box, $iframe);
            $changed = true;
        }

        // Links to audio/video files or video sites.
        foreach (iterator_to_array($xpath->query('.//a[@href]', $root)) as $link) {
            if (strpos(' ' . $link->getAttribute('class') . ' ', ' media-ref-link ') !== false) {
                continue;
            }
            $href = $link->getAttribute('href');
            $kind = $this->get_link_media_kind($href, $files);
            if ($kind === null) {
                continue;
            }
            $box = $this->build_media_reference($doc, $kind, [$href], $files, trim($link->textContent));
            $link->parentNode->replaceChild($box, $link);
            $changed = true;
        }

        if (!$changed) {
            return $html;
        }

        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $doc->saveHTML($child);
        }
        // The HTML4 parser does not know that <source> is a void element.
        return str_replace('</source>', '', $output);
    }

    /**
     * Build the static reference shown in place of an audio, video or embed.
     *
     * @param DOMDocument $doc the document being processed.
     * @param string $kind audio, video, externalvideo or embedded.
     * @param string[] $sources the URLs of the media.
     * @param array $files [contextid, component, filearea, itemid] of the text.
     * @param string $linktext the original link text, if the media was a link.
     * @return DOMElement
     */
    protected function build_media_reference(DOMDocument $doc, $kind, array $sources, array $files, $linktext = '') {
        $box = $doc->createElement('span');
        $box->setAttribute('class', 'media-ref media-ref-' . $kind);

        $label = $doc->createElement('span');
        $label->setAttribute('class', 'media-ref-label');
        $label->appendChild($doc->createTextNode(self::MEDIA_ICONS[$kind] . ' '
                . get_string('media' . $kind, 'qformat_xhtmlplus') . ': '));

        $names = [];
        foreach ($sources as $i => $src) {
            if ($i > 0) {
                $label->appendChild($doc->createTextNode(' | '));
            }
            [$name, $size] = $this->describe_media_source($src, $files);
            $names[] = $name;
            $a = $doc->createElement('a');
            $a->setAttribute('href', $src);
            $a->setAttribute('class', 'media-ref-link');
            $a->appendChild($doc->createTextNode($name));
            $label->appendChild($a);
            if ($size !== null) {
                $label->appendChild($doc->createTextNode(' (' . $size . ')'));
            }
        }
        if ($linktext !== '' && !in_array($linktext, $names, true)) {
            $label->appendChild($doc->createTextNode(' — ' . $linktext));
        }
        $box->appendChild($label);

        return $box;
    }

    /**
     * Name and size shown for a media source.
     *
     * @param string $src the URL, possibly starting with @@PLUGINFILE@@.
     * @param array $files [contextid, component, filearea, itemid] of the text.
     * @return array [name, size or null]
     */
    protected function describe_media_source($src, array $files) {
        if (strpos($src, '@@PLUGINFILE@@') !== 0) {
            return [$src, null];
        }
        $file = $this->find_stored_file($src, $files);
        if ($file) {
            return [$file->get_filename(), display_size($file->get_filesize())];
        }
        $path = rawurldecode(preg_replace('~[?#].*$~', '', $src));
        return [substr($path, strrpos($path, '/') + 1), null];
    }

    /**
     * Kind of media a link points to, or null for an ordinary link.
     *
     * @param string $href the link target.
     * @param array $files [contextid, component, filearea, itemid] of the text.
     * @return string|null audio, video, externalvideo or null.
     */
    protected function get_link_media_kind($href, array $files) {
        if (strpos($href, '@@PLUGINFILE@@') === 0) {
            $file = $this->find_stored_file($href, $files);
            if ($file) {
                $mimetype = $file->get_mimetype();
                if (strpos($mimetype, 'audio/') === 0) {
                    return 'audio';
                }
                if (strpos($mimetype, 'video/') === 0) {
                    return 'video';
                }
                return null;
            }
        } else if (!preg_match('~^https?://~i', $href)) {
            return null;
        } else if ($this->is_video_host($href)) {
            return 'externalvideo';
        }

        $path = (string) parse_url(str_replace('@@PLUGINFILE@@', 'http://x', $href), PHP_URL_PATH);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($extension, self::AUDIO_EXTENSIONS, true)) {
            return 'audio';
        }
        if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
            return 'video';
        }
        return null;
    }

    /**
     * Whether a URL points to a known video site.
     *
     * @param string $url
     * @return bool
     */
    protected function is_video_host($url) {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        foreach (self::VIDEO_HOSTS as $videohost) {
            if ($host === $videohost || substr($host, -strlen('.' . $videohost)) === '.' . $videohost) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the stored file referenced by an @@PLUGINFILE@@ URL.
     *
     * @param string $url the URL starting with @@PLUGINFILE@@.
     * @param array $files [contextid, component, filearea, itemid] of the text.
     * @return stored_file|null
     */
    protected function find_stored_file($url, array $files) {
        [$contextid, $component, $filearea, $itemid] = $files;
        $path = substr($url, strlen('@@PLUGINFILE@@'));
        $path = '/' . ltrim(rawurldecode(preg_replace('~[?#].*$~', '', $path)), '/');
        $pos = strrpos($path, '/');
        $filepath = substr($path, 0, $pos + 1);
        $filename = substr($path, $pos + 1);
        if ($filename === '') {
            return null;
        }
        $file = get_file_storage()->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
        return ($file && !$file->is_directory()) ? $file : null;
    }

    protected function writequestion($question) {
        if ($question->qtype == 'category') {
            return '';
        }

        $id = $question->id;
        $this->questionnumber++;
        $path = $this->category_breadcrumb($this->question_category_id($question));
        $label = $this->qtype_label($question->qtype);
        $this->index[$path][] = ['q-' . $id, $this->questionnumber, $question->name, $label];
        $this->typecounts[$label] = ($this->typecounts[$label] ?? 0) + 1;

        $text = $this->render_text($question->questiontext, $question->questiontextformat,
                $question, 'question', 'questiontext', $id);

        // Show the solution inside the text, where the student would answer.
        switch ($question->qtype) {
            case 'ddwtos':
            case 'gapselect':
                $text = $this->fill_choice_gaps($text, $this->get_gap_choices($question));
                break;
            case 'multianswer':
                $text = $this->fill_cloze_gaps($text, $question);
                break;
            case 'formulas':
                $text = $this->fill_formulas_parts($text, $question);
                break;
            case 'stack':
                $text = $this->stack_cas_markers($this->fill_stack_placeholders($text, $question));
                break;
        }

        $comment = str_replace('--', '- -', "question: {$id}  name: {$question->name}  type: {$question->qtype}");
        $expout = "<!-- {$comment} -->\n";
        $expout .= "<section class=\"q q-{$question->qtype}\" id=\"q-{$id}\">\n";
        $expout .= $this->render_question_header($question, $path);
        $expout .= '<div class="q-text">' . $text . "</div>\n";

        switch ($question->qtype) {
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
            case 'calculatedsimple':
            case 'calculatedmulti':
                $expout .= $this->write_calculated($question);
                break;
            case 'ddwtos':
            case 'gapselect':
                $expout .= $this->write_choice_gaps($question);
                break;
            case 'ddimageortext':
                $expout .= $this->write_ddimageortext($question);
                break;
            case 'ddmarker':
                $expout .= $this->write_ddmarker($question);
                break;
            case 'ordering':
                $expout .= $this->write_ordering($question);
                break;
            case 'formulas':
                $expout .= $this->write_formulas($question);
                break;
            case 'stack':
                $expout .= $this->write_stack($question);
                break;
            case 'description':
                break;
            default:
                $expout .= '<div class="q-unsupported">'
                        . s(get_string('exportnotimplemented', 'qformat_xhtmlplus', $question->qtype)) . "</div>\n";
        }

        $expout .= $this->render_combined_feedback($question);
        $expout .= $this->render_hints($question);
        $expout .= $this->render_general_feedback($question);
        $expout .= "</section>\n\n";
        return $expout;
    }

    /**
     * Format the feedback of an answer, with its files.
     *
     * @param stdClass $answer the answer record.
     * @param stdClass $question the question being exported.
     * @return string HTML.
     */
    protected function render_answer_feedback($answer, $question) {
        if (!isset($answer->feedback) || trim(strip_tags((string) $answer->feedback, '<img><audio><video>')) === '') {
            return '';
        }
        return $this->render_text($answer->feedback, $answer->feedbackformat ?? FORMAT_HTML, $question,
                'question', 'answerfeedback', $answer->id);
    }

    /**
     * Table of answers with grade and feedback.
     *
     * @param stdClass $question the question (for files and context).
     * @param array $answers answer records.
     * @param string $mode text, html, numerical or calculated.
     * @return string HTML
     */
    protected function render_answers_table($question, array $answers, $mode = 'text') {
        $showtolerance = in_array($mode, ['numerical', 'calculated']);
        $rows = '';
        foreach ($answers as $answer) {
            if ($mode === 'html') {
                $answertext = $this->render_text($answer->answer, $answer->answerformat ?? FORMAT_HTML,
                        $question, 'question', 'answer', $answer->id);
            } else if (trim((string) $answer->answer) === '') {
                $answertext = '<em>' . s($this->str('emptyanswer')) . '</em>';
            } else if (trim($answer->answer) === '*') {
                $answertext = '<em>' . s($this->str('anyotheranswer')) . '</em>';
            } else if ($mode === 'calculated') {
                $answertext = '<code>' . s($answer->answer) . '</code>';
            } else {
                $answertext = '<strong>' . s($answer->answer) . '</strong>';
            }
            $tolerance = '';
            if ($showtolerance && isset($answer->tolerance) && (float) $answer->tolerance != 0) {
                $tolerance = '± ' . s($this->number($answer->tolerance));
                if ($mode === 'calculated' && !empty($answer->tolerancetype)) {
                    $tolerance .= ' (' . s($this->str('tolerancetype' . (int) $answer->tolerancetype)) . ')';
                }
            }
            $rows .= '<tr class="' . $this->fraction_class($answer->fraction) . '"><td class="col-grade">'
                    . $this->fraction_badge($answer->fraction) . '</td><td>' . $answertext . '</td>'
                    . ($showtolerance ? '<td>' . $tolerance . '</td>' : '')
                    . '<td class="col-feedback">' . $this->render_answer_feedback($answer, $question) . "</td></tr>\n";
        }
        $head = '<tr><th>' . s($this->str('grade')) . '</th><th>' . s($this->str('answer')) . '</th>'
                . ($showtolerance ? '<th>' . s($this->str('tolerance')) . '</th>' : '')
                . '<th>' . s($this->str('feedback')) . "</th></tr>\n";
        return "<table class=\"answers-table\">\n<thead>$head</thead>\n<tbody>\n$rows</tbody>\n</table>\n";
    }

    /**
     * True/false: the correct value is the answer with a positive grade.
     */
    protected function write_truefalse($question) {
        $answers = $question->options->answers ?? [];
        $true = $answers[$question->options->trueanswer ?? 0] ?? null;
        $false = $answers[$question->options->falseanswer ?? 0] ?? null;
        if (!$true || !$false) {
            // Fallback: Moodle saves "true" first.
            $list = array_values($answers);
            $true = $list[0] ?? null;
            $false = $list[1] ?? null;
        }

        $items = '';
        foreach ([[get_string('true', 'qtype_truefalse'), $true], [get_string('false', 'qtype_truefalse'), $false]] as $item) {
            [$labeltext, $answer] = $item;
            if (!$answer) {
                continue;
            }
            $correct = $answer->fraction > 0;
            $items .= '<li class="choice ' . ($correct ? 'is-correct' : 'is-wrong') . '"><span class="choice-mark">'
                    . ($correct ? '✔' : '') . '</span><div class="choice-body"><div class="choice-text">'
                    . s($labeltext) . '</div>';
            $feedback = $this->render_answer_feedback($answer, $question);
            if ($feedback !== '') {
                $items .= '<div class="choice-feedback">' . $feedback . '</div>';
            }
            $items .= "</div></li>\n";
        }
        return "<ul class=\"choices no-numbers\">\n$items</ul>\n";
    }

    /**
     * Multiple choice: every option, correct ones marked, grade and feedback.
     */
    protected function write_multichoice($question) {
        $single = !empty($question->options->single);
        $numbering = ['abc' => 'a', 'ABCD' => 'A', '123' => '1', 'iii' => 'i', 'IIII' => 'I', 'none' => ''];
        $type = $numbering[$question->options->answernumbering ?? 'abc'] ?? 'a';

        $expout = '<p class="q-info">' . s($this->str($single ? 'singleanswer' : 'multipleanswers')) . "</p>\n";
        $expout .= '<ol class="choices' . ($type === '' ? ' no-numbers' : '') . '"'
                . ($type !== '' ? ' type="' . $type . '"' : '') . ">\n";
        foreach ($question->options->answers as $answer) {
            $fraction = (float) $answer->fraction;
            $correct = $fraction > 0;
            $showgrade = !$single || ($fraction != 0 && $fraction < 0.9999999);
            $expout .= '<li class="choice ' . $this->fraction_class($fraction) . '"><span class="choice-mark">'
                    . ($correct ? '✔' : '') . '</span><div class="choice-body"><div class="choice-text">'
                    . $this->render_text($answer->answer, $answer->answerformat, $question, 'question', 'answer', $answer->id)
                    . '</div>';
            $feedback = $this->render_answer_feedback($answer, $question);
            if ($feedback !== '') {
                $expout .= '<div class="choice-feedback">' . $feedback . '</div>';
            }
            $expout .= '</div>' . ($showgrade ? $this->fraction_badge($fraction) : '') . "</li>\n";
        }
        $expout .= "</ol>\n";
        return $expout;
    }

    /**
     * Short answer: all accepted answers with grades and feedback.
     */
    protected function write_shortanswer($question) {
        $expout = '<p class="q-info">' . s($this->str(empty($question->options->usecase) ? 'caseinsensitive' : 'casesensitive'))
                . "</p>\n";
        return $expout . $this->render_answers_table($question, $question->options->answers, 'text');
    }

    /**
     * Numerical: answers with tolerance, and units if any.
     */
    protected function write_numerical($question) {
        $expout = $this->render_answers_table($question, $question->options->answers, 'numerical');
        if (!empty($question->options->units)) {
            $units = [];
            foreach ($question->options->units as $unit) {
                $units[] = s($unit->unit) . (isset($unit->multiplier) && (float) $unit->multiplier != 1
                        ? ' (× ' . s($this->number($unit->multiplier)) . ')' : '');
            }
            $expout .= '<p class="q-info">' . s($this->str('units')) . ': ' . implode(', ', $units) . "</p>\n";
        }
        return $expout;
    }

    /**
     * Matching: each item with its correct answer, then the distractors.
     */
    protected function write_match($question) {
        $rows = '';
        $extra = [];
        foreach ($question->options->subquestions as $subquestion) {
            if (trim(strip_tags($subquestion->questiontext, '<img><audio><video>')) === '') {
                $extra[] = s($subquestion->answertext);
                continue;
            }
            $rows .= '<tr class="is-correct"><td>' . $this->render_text($subquestion->questiontext,
                    $subquestion->questiontextformat, $question, 'qtype_match', 'subquestion', $subquestion->id)
                    . '</td><td class="col-arrow">→</td><td><strong>' . s($subquestion->answertext) . "</strong></td></tr>\n";
        }
        $expout = '<table class="answers-table match-table"><thead><tr><th>' . s($this->str('item')) . '</th><th></th><th>'
                . s($this->str('correctanswer')) . "</th></tr></thead>\n<tbody>\n$rows</tbody></table>\n";
        if ($extra) {
            $expout .= '<p class="q-info">' . s($this->str('extraanswers')) . ': ' . implode(', ', $extra) . "</p>\n";
        }
        return $expout;
    }

    /**
     * Essay: response settings, information for graders, response template.
     */
    protected function write_essay($question) {
        $options = $question->options ?? new stdClass();
        $rows = '';
        if (!empty($options->responseformat)) {
            $key = 'format' . $options->responseformat;
            $value = get_string_manager()->string_exists($key, 'qtype_essay')
                    ? get_string($key, 'qtype_essay') : $options->responseformat;
            $rows .= $this->kv_row($this->str('responseformat'), s($value));
        }
        if (isset($options->responserequired)) {
            $rows .= $this->kv_row($this->str('responserequired'), s($options->responserequired ? get_string('yes') : get_string('no')));
        }
        if (!empty($options->responsefieldlines)) {
            $rows .= $this->kv_row($this->str('responsefieldlines'), s($options->responsefieldlines));
        }
        if (!empty($options->minwordlimit) || !empty($options->maxwordlimit)) {
            $rows .= $this->kv_row($this->str('wordlimit'), s(($options->minwordlimit ?: '0') . ' – '
                    . ($options->maxwordlimit ?: '∞')));
        }
        if (isset($options->attachments)) {
            $rows .= $this->kv_row($this->str('attachments'),
                    s($options->attachments == -1 ? $this->str('unlimited') : $options->attachments));
            if (!empty($options->attachments) && isset($options->attachmentsrequired)) {
                $rows .= $this->kv_row($this->str('attachmentsrequired'), s($options->attachmentsrequired));
            }
            if (!empty($options->attachments) && !empty($options->filetypeslist)) {
                $rows .= $this->kv_row($this->str('acceptedfiletypes'),
                        s(implode(', ', preg_split('~[\s,;]+~', trim($options->filetypeslist)))));
            }
        }
        $expout = $rows !== '' ? "<table class=\"kv\">\n$rows</table>\n" : '';

        if (!empty($options->graderinfo) && trim(strip_tags($options->graderinfo, '<img>')) !== '') {
            $expout .= $this->section($this->str('graderinfo'), $this->render_text($options->graderinfo,
                    $options->graderinfoformat ?? FORMAT_HTML, $question, 'qtype_essay', 'graderinfo', $question->id),
                    'q-solution');
        }
        if (!empty($options->responsetemplate) && trim(strip_tags($options->responsetemplate)) !== '') {
            $expout .= $this->section($this->str('responsetemplate'), $this->render_text($options->responsetemplate,
                    $options->responsetemplateformat ?? FORMAT_HTML, $question, 'qtype_essay', 'responsetemplate',
                    $question->id));
        }
        return $expout;
    }

    /**
     * One row of a key/value table.
     *
     * @param string $key plain text
     * @param string $value HTML
     * @return string HTML
     */
    protected function kv_row($key, $value) {
        return '<tr><th>' . s($key) . '</th><td>' . $value . "</td></tr>\n";
    }

    /**
     * Correct answers of a cloze subquestion, as plain text.
     *
     * @param stdClass $subquestion
     * @return string[]
     */
    protected function cloze_correct_answers($subquestion) {
        $best = [];
        $max = 0;
        foreach ($subquestion->options->answers ?? [] as $answer) {
            if ($answer->fraction > $max + 0.0000001) {
                $max = $answer->fraction;
                $best = [];
            }
            if ($answer->fraction > 0 && abs($answer->fraction - $max) < 0.0000001) {
                $best[] = trim(html_entity_decode(strip_tags($answer->answer), ENT_QUOTES, 'UTF-8'));
            }
        }
        return $best;
    }

    /**
     * Subquestions of a cloze question in text order ({#1}, {#2}...).
     *
     * @param stdClass $question
     * @return array number => subquestion (or null if missing)
     */
    protected function cloze_subquestions($question) {
        $list = [];
        $n = 0;
        foreach ($question->options->questions ?? [] as $subquestion) {
            $list[++$n] = $subquestion ?: null;
        }
        return $list;
    }

    /**
     * Replace {#n} in a cloze text with the correct answers.
     */
    protected function fill_cloze_gaps($text, $question) {
        $subquestions = $this->cloze_subquestions($question);
        return preg_replace_callback('~\{#(\d+)\}~', function ($m) use ($subquestions) {
            $n = (int) $m[1];
            $subquestion = $subquestions[$n] ?? null;
            $answers = $subquestion ? $this->cloze_correct_answers($subquestion) : [];
            return $this->gap_chip($n, $answers ? implode(' / ', array_map('s', $answers)) : '?');
        }, $text);
    }

    /**
     * Inline marker showing the solution of a gap.
     *
     * @param string $number gap number or label
     * @param string $content HTML
     * @return string HTML
     */
    protected function gap_chip($number, $content) {
        return '<span class="gap"><span class="gap-n">' . s($number) . '</span><span class="gap-a">' . $content
                . '</span></span>';
    }

    /**
     * Cloze: every gap with its type and all its answers.
     */
    protected function write_multianswer($question) {
        $expout = '';
        foreach ($this->cloze_subquestions($question) as $n => $subquestion) {
            if (!$subquestion || empty($subquestion->options->answers)) {
                continue;
            }
            $mode = $subquestion->qtype === 'numerical' ? 'numerical'
                    : ($subquestion->qtype === 'multichoice' ? 'html' : 'text');
            $title = $this->str('gapn', $n) . ' · ' . $this->qtype_label($subquestion->qtype);
            if (!empty($subquestion->defaultmark)) {
                $title .= ' · ' . $this->str($subquestion->defaultmark == 1 ? 'pointone' : 'points',
                        $this->number($subquestion->defaultmark));
            }
            $expout .= $this->section($title, $this->render_answers_table($question, $subquestion->options->answers, $mode),
                    'q-gap');
        }
        return $expout;
    }

    /**
     * Choices of ddwtos / gapselect, numbered as in the [[n]] placeholders.
     *
     * @param stdClass $question
     * @return array number => object with text, group, infinite
     */
    protected function get_gap_choices($question) {
        $answers = $question->options->answers ?? [];
        uasort($answers, function ($a, $b) {
            return $a->id <=> $b->id;
        });
        $choices = [];
        $n = 0;
        foreach ($answers as $answer) {
            $group = 1;
            $infinite = false;
            if (is_numeric($answer->feedback)) {
                $group = (int) $answer->feedback;
            } else {
                $data = @unserialize((string) $answer->feedback, ['allowed_classes' => ['stdClass']]);
                if (is_object($data)) {
                    $group = (int) ($data->draggroup ?? 1);
                    $infinite = !empty($data->infinite);
                }
            }
            $choices[++$n] = (object) [
                // Keep simple formatting only, and let Moodle's sanitiser drop any attributes or scripts.
                'text' => clean_text(strip_tags($answer->answer, '<sub><sup><b><i><em><strong><u>'), FORMAT_HTML),
                'group' => $group,
                'infinite' => $infinite,
            ];
        }
        return $choices;
    }

    /**
     * Replace [[n]] with the choice that goes there.
     */
    protected function fill_choice_gaps($text, array $choices) {
        $gap = 0;
        return preg_replace_callback('~\[\[(\d+)\]\]~', function ($m) use ($choices, &$gap) {
            $gap++;
            $choice = $choices[(int) $m[1]] ?? null;
            return $this->gap_chip($gap, $choice ? $choice->text : '?');
        }, $text);
    }

    /**
     * ddwtos / gapselect: choices by group, with the gaps where each one goes.
     */
    protected function write_choice_gaps($question) {
        $choices = $this->get_gap_choices($question);
        preg_match_all('~\[\[(\d+)\]\]~', $question->questiontext, $matches);
        $places = [];
        foreach ($matches[1] as $i => $choiceno) {
            $places[(int) $choiceno][] = $this->str('gapn', $i + 1);
        }
        $usegroups = count(array_unique(array_map(function ($c) {
            return $c->group;
        }, $choices))) > 1;

        $rows = '';
        foreach ($choices as $n => $choice) {
            $used = isset($places[$n]);
            $rows .= '<tr class="' . ($used ? 'is-correct' : 'is-wrong') . '">'
                    . ($usegroups ? '<td>' . s($choice->group) . '</td>' : '')
                    . '<td>' . $choice->text . ($choice->infinite ? ' <span class="muted">∞</span>' : '') . '</td><td>'
                    . ($used ? '✔ ' . s(implode(', ', $places[$n])) : '<span class="muted">' . s($this->str('distractor')) . '</span>')
                    . "</td></tr>\n";
        }
        return '<table class="answers-table"><thead><tr>' . ($usegroups ? '<th>' . s($this->str('group')) . '</th>' : '')
                . '<th>' . s($this->str('choices')) . '</th><th>' . s($this->str('correctplace')) . "</th></tr></thead>\n"
                . "<tbody>\n$rows</tbody></table>\n";
    }

    /**
     * Calculated types: formulas, tolerance, variables and one example.
     */
    protected function write_calculated($question) {
        $answers = $question->options->answers ?? [];
        $multi = $question->qtype === 'calculatedmulti';
        $expout = $this->render_answers_table($question, $answers, $multi ? 'html' : 'calculated');
        if ($multi) {
            // Formulas inside the choices: {={a}+{b}} -> = {a}+{b}.
            $expout = preg_replace('~\{=((?:[^{}]|\{[^{}]*\})*)\}~', '<code>= $1</code>', $expout);
        }

        // Variables (datasets): Moodle does not always load them with the question.
        $datasets = $question->options->datasets ?? [];
        if (!$datasets) {
            $datasets = $this->calculated_datasets($question->id);
        }
        if ($datasets) {
            $rows = '';
            foreach ($datasets as $dataset) {
                $range = isset($dataset->minimum, $dataset->maximum)
                        ? s($this->number($dataset->minimum)) . ' – ' . s($this->number($dataset->maximum)) : '';
                $values = [];
                foreach (array_slice((array) ($dataset->items ?? []), 0, 5) as $item) {
                    $values[] = s($this->number($item->value));
                }
                $rows .= '<tr><td><code>{' . s($dataset->name) . '}</code></td><td>' . $range . '</td><td>'
                        . implode(', ', $values) . (count((array) ($dataset->items ?? [])) > 5 ? ', …' : '') . "</td></tr>\n";
            }
            $expout .= $this->section($this->str('variables'), '<table class="answers-table"><thead><tr><th>'
                    . s($this->str('variable')) . '</th><th>' . s($this->str('range')) . '</th><th>'
                    . s($this->str('values')) . "</th></tr></thead>\n<tbody>\n$rows</tbody></table>");
        } else {
            $expout .= '<p class="q-info muted">' . s($this->str('notevaluated')) . "</p>\n";
        }
        return $expout;
    }

    /**
     * Variables of a calculated question, read from the dataset tables.
     *
     * @param int $questionid
     * @return array of objects with name, minimum, maximum, items
     */
    protected function calculated_datasets($questionid) {
        global $DB;
        try {
            $definitions = $DB->get_records_sql('SELECT d.id, d.name, d.options, d.itemcount
                                                   FROM {question_dataset_definitions} d
                                                   JOIN {question_datasets} qd ON qd.datasetdefinition = d.id
                                                  WHERE qd.question = ?
                                               ORDER BY d.name', [$questionid]);
        } catch (Throwable $e) {
            return [];
        }
        $datasets = [];
        foreach ($definitions as $definition) {
            // Options look like "uniform:1.0:10.0:1" (distribution, minimum, maximum, decimals).
            $parts = explode(':', (string) $definition->options);
            $datasets[] = (object) [
                'name' => $definition->name,
                'minimum' => isset($parts[1]) && is_numeric($parts[1]) ? $parts[1] : null,
                'maximum' => isset($parts[2]) && is_numeric($parts[2]) ? $parts[2] : null,
                'items' => $DB->get_records('question_dataset_items', ['definition' => $definition->id],
                        'itemnumber', 'itemnumber, value', 0, 6),
            ];
        }
        return $datasets;
    }

    /**
     * Ordering: items in the correct order.
     */
    protected function write_ordering($question) {
        $answers = array_values($question->options->answers ?? []);
        usort($answers, function ($a, $b) {
            return [(float) $a->fraction, $a->id] <=> [(float) $b->fraction, $b->id];
        });
        $items = '';
        foreach ($answers as $answer) {
            $items .= '<li>' . $this->render_text($answer->answer, $answer->answerformat ?? FORMAT_HTML,
                    $question, 'question', 'answer', $answer->id) . "</li>\n";
        }
        return $this->section($this->str('correctorder'), "<ol class=\"ordering\">\n$items</ol>", 'q-solution');
    }

    /**
     * First image of a file area, embedded, with its size.
     *
     * @param stdClass $question
     * @param string $component
     * @param string $filearea
     * @param int $itemid
     * @return array|null [src, width, height]
     */
    protected function area_image($question, $component, $filearea, $itemid) {
        $files = get_file_storage()->get_area_files($question->contextid, $component, $filearea, $itemid, 'id', false);
        foreach ($files as $file) {
            if (strpos($file->get_mimetype(), 'image/') !== 0) {
                continue;
            }
            try {
                $content = @$file->get_content();
            } catch (Throwable $e) {
                $content = '';
            }
            if ($content === false || $content === '') {
                return null;
            }
            $size = @getimagesizefromstring($content);
            return ['data:' . $file->get_mimetype() . ';base64,' . base64_encode($content),
                    $size ? $size[0] : 0, $size ? $size[1] : 0];
        }
        return null;
    }

    /**
     * Background image with an SVG overlay drawn on top.
     *
     * @param array|null $image [src, width, height]
     * @param string $overlay SVG elements
     * @return string HTML
     */
    protected function image_with_overlay($image, $overlay) {
        if (!$image || !$image[1] || !$image[2]) {
            return '<p class="q-info muted">' . s($this->str('nobackground')) . "</p>\n";
        }
        [$src, $width, $height] = $image;
        return '<div class="dd-figure"><svg viewBox="0 0 ' . $width . ' ' . $height . '" width="' . $width
                . '" xmlns="http://www.w3.org/2000/svg" role="img"><image href="' . $src . '" x="0" y="0" width="' . $width
                . '" height="' . $height . '"/>' . $overlay . "</svg></div>\n";
    }

    /**
     * SVG label at a point.
     */
    protected function svg_label($x, $y, $text, $number) {
        $label = $number . '. ' . trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
        $width = 14 + 7.5 * mb_strlen($label);
        return '<g class="dd-label"><rect x="' . $x . '" y="' . $y . '" width="' . $width . '" height="24" rx="4"/>'
                . '<text x="' . ($x + 7) . '" y="' . ($y + 17) . '">' . s($label) . '</text></g>';
    }

    /**
     * Drag and drop onto image: background with the correct items in place.
     */
    protected function write_ddimageortext($question) {
        $drags = [];
        foreach ($question->options->drags ?? [] as $drag) {
            $drags[(int) $drag->no] = $drag;
        }
        $overlay = '';
        $rows = '';
        $placed = [];
        foreach ($question->options->drops ?? [] as $drop) {
            $drag = $drags[(int) $drop->choice] ?? null;
            $text = $drag ? ($drag->label !== '' ? $drag->label : '#' . $drag->no) : '?';
            $placed[(int) $drop->choice] = true;
            $overlay .= $this->svg_label((int) $drop->xleft, (int) $drop->ytop, $text, $drop->no);
            $rows .= '<tr class="is-correct"><td>' . s($drop->no) . '</td><td>' . s($drop->label ?? '') . '</td><td>'
                    . s((int) $drop->xleft . ', ' . (int) $drop->ytop) . '</td><td><strong>' . s($text) . "</strong></td></tr>\n";
        }
        $expout = $this->image_with_overlay($this->area_image($question, 'qtype_ddimageortext', 'bgimage', $question->id),
                $overlay);
        $expout .= '<table class="answers-table"><thead><tr><th>' . s($this->str('zone')) . '</th><th>'
                . s($this->str('label')) . '</th><th>' . s($this->str('position')) . '</th><th>'
                . s($this->str('correctanswer')) . "</th></tr></thead>\n<tbody>\n$rows</tbody></table>\n";
        $unused = [];
        foreach ($drags as $no => $drag) {
            if (empty($placed[$no])) {
                $unused[] = s($drag->label !== '' ? $drag->label : '#' . $no);
            }
        }
        if ($unused) {
            $expout .= '<p class="q-info">' . s($this->str('unuseditems')) . ': ' . implode(', ', $unused) . "</p>\n";
        }
        return $expout;
    }

    /**
     * Drag markers: background with the drop zones drawn and named.
     */
    protected function write_ddmarker($question) {
        $drags = [];
        foreach ($question->options->drags ?? [] as $drag) {
            $drags[(int) $drag->no] = $drag;
        }
        $overlay = '';
        $rows = '';
        $placed = [];
        foreach ($question->options->drops ?? [] as $drop) {
            $drag = $drags[(int) $drop->choice] ?? null;
            $text = $drag ? $drag->label : '?';
            $placed[(int) $drop->choice] = true;
            [$shapesvg, $x, $y] = $this->marker_shape($drop->shape, $drop->coords);
            $overlay .= $shapesvg . $this->svg_label($x, $y, $text, $drop->no);
            $rows .= '<tr class="is-correct"><td>' . s($drop->no) . '</td><td>' . s($drop->shape) . ' <code>'
                    . s($drop->coords) . '</code></td><td><strong>' . s($text) . "</strong></td></tr>\n";
        }
        $expout = $this->image_with_overlay($this->area_image($question, 'qtype_ddmarker', 'bgimage', $question->id),
                $overlay);
        $expout .= '<table class="answers-table"><thead><tr><th>' . s($this->str('zone')) . '</th><th>'
                . s($this->str('shape')) . '</th><th>' . s($this->str('correctanswer'))
                . "</th></tr></thead>\n<tbody>\n$rows</tbody></table>\n";
        $unused = [];
        foreach ($drags as $no => $drag) {
            if (empty($placed[$no])) {
                $unused[] = s($drag->label);
            }
        }
        if ($unused) {
            $expout .= '<p class="q-info">' . s($this->str('unuseditems')) . ': ' . implode(', ', $unused) . "</p>\n";
        }
        return $expout;
    }

    /**
     * SVG for a ddmarker drop zone, and where to put its label.
     *
     * @param string $shape circle, rectangle or polygon
     * @param string $coords Moodle coordinates
     * @return array [svg, labelx, labely]
     */
    protected function marker_shape($shape, $coords) {
        $points = [];
        foreach (explode(';', (string) $coords) as $pair) {
            $points[] = array_map('floatval', explode(',', $pair));
        }
        $first = $points[0] + [0, 0];
        switch ($shape) {
            case 'circle':
                $r = $points[1][0] ?? 0;
                return ['<circle class="dd-zone" cx="' . $first[0] . '" cy="' . $first[1] . '" r="' . $r . '"/>',
                        $first[0] - $r, $first[1] + $r + 4];
            case 'rectangle':
                $w = $points[1][0] ?? 0;
                $h = $points[1][1] ?? 0;
                return ['<rect class="dd-zone" x="' . $first[0] . '" y="' . $first[1] . '" width="' . $w . '" height="'
                        . $h . '"/>', $first[0], $first[1] + $h + 4];
            default:
                $list = implode(' ', array_map(function ($p) {
                    return ($p[0] ?? 0) . ',' . ($p[1] ?? 0);
                }, $points));
                $maxy = max(array_map(function ($p) {
                    return $p[1] ?? 0;
                }, $points));
                return ['<polygon class="dd-zone" points="' . $list . '"/>', $first[0], $maxy + 4];
        }
    }

    /**
     * Parts of a formulas question, ordered.
     *
     * @param stdClass $question
     * @return array
     */
    protected function formulas_parts($question) {
        $parts = array_values($question->options->answers ?? $question->parts ?? []);
        usort($parts, function ($a, $b) {
            return ((int) ($a->partindex ?? 0)) <=> ((int) ($b->partindex ?? 0));
        });
        return $parts;
    }

    /**
     * Replace the part placeholders ({#1}...) in a formulas text with a marker.
     */
    protected function fill_formulas_parts($text, $question) {
        foreach ($this->formulas_parts($question) as $i => $part) {
            if (!empty($part->placeholder)) {
                $text = str_replace('{' . $part->placeholder . '}', $this->gap_chip($i + 1, s($this->str('partn', $i + 1))),
                        $text);
            }
        }
        return $text;
    }

    /**
     * Grading criterion of a formulas part in words, with the original rule.
     *
     * @param string $rule e.g. "_relerr < 0.01"
     * @return string HTML
     */
    protected function formulas_correctness($rule) {
        $code = '<code>' . s($rule) . '</code>';
        if (preg_match('~^\s*_(relerr|err)\s*<=?\s*([0-9.eE+-]+)\s*$~', $rule, $m) && is_numeric($m[2])) {
            $value = $m[1] === 'relerr' ? $this->number($m[2] * 100) . '%' : $this->number($m[2]);
            return s($this->str($m[1] === 'relerr' ? 'relerrbelow' : 'abserrbelow', $value)) . ' ' . $code;
        }
        return $code;
    }

    /**
     * Formulas: variables, then every part with its answer and grading rules.
     */
    protected function write_formulas($question) {
        $options = $question->options;
        $expout = '';
        $vars = '';
        if (trim((string) ($options->varsrandom ?? '')) !== '') {
            $vars .= $this->kv_row($this->str('randomvars'), '<pre>' . s($options->varsrandom) . '</pre>');
        }
        if (trim((string) ($options->varsglobal ?? '')) !== '') {
            $vars .= $this->kv_row($this->str('globalvars'), '<pre>' . s($options->varsglobal) . '</pre>');
        }
        if ($vars !== '') {
            $expout .= $this->section($this->str('variables'), "<table class=\"kv\">\n$vars</table>");
        }

        foreach ($this->formulas_parts($question) as $i => $part) {
            $content = '';
            if (trim(strip_tags((string) ($part->subqtext ?? ''), '<img>')) !== '') {
                $subqtext = $this->render_text($part->subqtext, $part->subqtextformat ?? FORMAT_HTML, $question,
                        'qtype_formulas', 'answersubqtext', $part->id);
                $subqtext = preg_replace_callback('~\{_(\d+|u)\}~', function ($m) {
                    $label = $m[1] === 'u' ? $this->str('unitbox') : $this->str('boxn', (int) $m[1] + 1);
                    return '<span class="gap gap-box"><span class="gap-n">' . s($label) . '</span></span>';
                }, $subqtext);
                $content .= '<div class="part-text">' . $subqtext . '</div>';
            }
            $rows = '';
            $rows .= $this->kv_row($this->str('correctanswer'), '<code>' . s($part->answer ?? '') . '</code>');
            if (isset($part->answertype)) {
                $key = 'answertype' . (int) $part->answertype;
                $rows .= $this->kv_row($this->str('answertype'),
                        s(get_string_manager()->string_exists($key, 'qformat_xhtmlplus') ? $this->str($key) : $part->answertype));
            }
            if (trim((string) ($part->correctness ?? '')) !== '') {
                $rows .= $this->kv_row($this->str('correctness'), $this->formulas_correctness($part->correctness));
            }
            if (trim((string) ($part->postunit ?? '')) !== '') {
                $rows .= $this->kv_row($this->str('unit'), s($part->postunit));
            }
            if (isset($part->answermark)) {
                $rows .= $this->kv_row($this->str('partmark'), s($this->number($part->answermark)));
            }
            if (trim((string) ($part->vars1 ?? '')) !== '') {
                $rows .= $this->kv_row($this->str('localvars'), '<pre>' . s($part->vars1) . '</pre>');
            }
            if (trim((string) ($part->vars2 ?? '')) !== '') {
                $rows .= $this->kv_row($this->str('gradingvars'), '<pre>' . s($part->vars2) . '</pre>');
            }
            $content .= "<table class=\"kv\">\n$rows</table>\n";
            if (trim(strip_tags((string) ($part->feedback ?? ''), '<img>')) !== '') {
                $content .= '<div class="choice-feedback">' . $this->render_text($part->feedback,
                        $part->feedbackformat ?? FORMAT_HTML, $question, 'qtype_formulas', 'answerfeedback', $part->id)
                        . '</div>';
            }
            $expout .= $this->section($this->str('partn', $i + 1), $content, 'q-gap');
        }
        $expout .= '<p class="q-info muted">' . s($this->str('notevaluated')) . "</p>\n";
        return $expout;
    }

    /**
     * STACK inputs, wherever get_question_options() put them.
     *
     * @param stdClass $question
     * @return array name => input record
     */
    protected function stack_inputs($question) {
        $inputs = [];
        foreach ($question->inputs ?? $question->options->inputs ?? [] as $key => $input) {
            $inputs[$input->name ?? $key] = $input;
        }
        return $inputs;
    }

    /**
     * STACK potential response trees, wherever get_question_options() put them.
     *
     * @param stdClass $question
     * @return array name => prt record (with ->nodes)
     */
    protected function stack_prts($question) {
        $prts = [];
        foreach ($question->prts ?? $question->options->prts ?? [] as $key => $prt) {
            $prts[$prt->name ?? $key] = $prt;
        }
        return $prts;
    }

    /**
     * Replace STACK placeholders: inputs show the teacher's answer, validation tags disappear,
     * feedback tags become a small marker.
     *
     * @param string $text HTML
     * @param stdClass $question
     * @return string HTML
     */
    protected function fill_stack_placeholders($text, $question) {
        $inputs = $this->stack_inputs($question);
        $text = preg_replace_callback('~\[\[input:([A-Za-z0-9_]+)\]\]~', function ($m) use ($inputs, $question) {
            $input = $inputs[$m[1]] ?? null;
            if (!$input) {
                return $this->gap_chip($m[1], '?');
            }
            $answer = $this->stack_teacher_answer($question, $input);
            if ($answer->options) {
                $content = s(implode(' / ', $answer->options));
            } else if ($answer->value !== null && mb_strlen(trim($answer->value)) <= 40) {
                $content = '<code>' . s(trim($answer->value)) . '</code>';
            } else {
                $content = $answer->tans !== '' ? '<code>' . s($answer->tans) . '</code>' : '?';
            }
            return $this->gap_chip($m[1], $content);
        }, $text);
        $text = preg_replace('~\[\[validation:[A-Za-z0-9_]+\]\]~', '', $text);
        return preg_replace_callback('~\[\[feedback:([A-Za-z0-9_]+)\]\]~', function ($m) {
            return '<span class="gap gap-box"><span class="gap-n">' . s($this->str('feedbackof', $m[1])) . '</span></span>';
        }, $text);
    }

    /**
     * Variables defined by the author (question and feedback variables), as name => expression.
     *
     * @param stdClass $question
     * @return array
     */
    protected function stack_variables($question) {
        $sources = [(string) ($question->options->questionvariables ?? '')];
        foreach ($this->stack_prts($question) as $prt) {
            $sources[] = (string) ($prt->feedbackvariables ?? '');
        }
        $vars = [];
        foreach ($sources as $source) {
            foreach (preg_split('~;|\R~', $source) as $statement) {
                if (preg_match('~^\s*([A-Za-z_][A-Za-z0-9_]*)\s*:(?!=)\s*(.+?)\s*$~s', $statement, $m)
                        && !isset($vars[$m[1]])) {
                    $vars[$m[1]] = $m[2];
                }
            }
        }
        return $vars;
    }

    /**
     * The teacher's answer of a STACK input, made readable:
     * - if the input's answer is just the input itself or empty, the answer tested in the response tree;
     * - if it is a variable, its definition;
     * - for dropdown, radio and checkbox, the labels of the correct options.
     *
     * @param stdClass $question
     * @param stdClass $input
     * @return stdClass tans, value (or null), options (array), fromtree (bool)
     */
    protected function stack_teacher_answer($question, $input) {
        $name = $input->name ?? '';
        $tans = trim((string) ($input->tans ?? ''));
        $fromtree = false;
        if ($tans === '' || $tans === $name) {
            foreach ($this->stack_prts($question) as $prt) {
                foreach ((array) ($prt->nodes ?? []) as $node) {
                    if (trim((string) ($node->sans ?? '')) === $name && trim((string) ($node->tans ?? '')) !== '') {
                        $tans = trim($node->tans);
                        $fromtree = true;
                        break 2;
                    }
                }
            }
        }
        $vars = $this->stack_variables($question);
        $value = $vars[$tans] ?? null;

        $options = [];
        if (in_array($input->type ?? '', ['dropdown', 'radio', 'checkbox'])) {
            $list = $value ?? $tans;
            if (preg_match_all('~\[\s*([^\[\],]+?)\s*,\s*true\s*(?:,\s*"((?:[^"\\\\]|\\\\.)*)")?\s*\]~', $list, $matches,
                    PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $options[] = isset($match[2]) && $match[2] !== '' ? stripcslashes($match[2]) : trim($match[1]);
                }
            }
        }

        return (object) ['tans' => $tans, 'value' => $value, 'options' => $options, 'fromtree' => $fromtree];
    }

    /**
     * Shorten a long expression for inline display.
     *
     * @param string $text
     * @param int $length
     * @return string
     */
    protected function shorten($text, $length) {
        $text = preg_replace('~\s+~', ' ', trim($text));
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    /**
     * STACK: variables, inputs with the teacher's answers, specific feedback, response trees.
     * Nothing is sent to Maxima: expressions are shown as written by the author.
     */
    protected function write_stack($question) {
        $options = $question->options ?? new stdClass();
        $expout = '';

        // Visible part: the inputs with the correct answers, and the feedback the student sees.
        $inputs = $this->stack_inputs($question);
        if ($inputs) {
            $rows = '';
            foreach ($inputs as $name => $input) {
                $details = [];
                if (!empty($input->syntaxhint)) {
                    $details[] = $this->str('syntaxhint') . ': <code>' . s($input->syntaxhint) . '</code>';
                }
                if (!empty($input->forbidwords)) {
                    $details[] = $this->str('forbidwords') . ': <code>' . s($input->forbidwords) . '</code>';
                }
                if (!empty($input->allowwords)) {
                    $details[] = $this->str('allowwords') . ': <code>' . s($input->allowwords) . '</code>';
                }
                if (!empty($input->options)) {
                    $details[] = $this->str('extraoptions') . ': <code>' . s($input->options) . '</code>';
                }
                $answer = $this->stack_teacher_answer($question, $input);
                if ($answer->options) {
                    $answerhtml = '<strong>' . s(implode(' / ', $answer->options)) . '</strong>';
                } else if ($answer->value !== null) {
                    $answerhtml = '<code>' . s($answer->value) . '</code><br><span class="muted">'
                            . s($this->str('variablename', $answer->tans)) . '</span>';
                } else {
                    $answerhtml = '<code>' . s($answer->tans) . '</code>';
                }
                if ($answer->fromtree) {
                    $answerhtml .= '<br><span class="muted">' . s($this->str('fromtree')) . '</span>';
                }
                $rows .= '<tr class="is-correct"><td><code>' . s($name) . '</code></td><td>'
                        . s($this->stack_input_type_label($input->type ?? '')) . '</td><td>' . $answerhtml . '</td><td>'
                        . implode('<br>', $details) . "</td></tr>\n";
            }
            $expout .= $this->section($this->str('inputs'), '<table class="answers-table"><thead><tr><th>'
                    . s($this->str('input')) . '</th><th>' . s($this->str('inputtype')) . '</th><th>'
                    . s($this->str('teacheranswer')) . '</th><th>' . s($this->str('details'))
                    . "</th></tr></thead>\n<tbody>\n$rows</tbody></table>", 'q-solution');
        }

        // Specific feedback, only if it says more than the [[feedback:...]] placeholders.
        $specific = (string) ($options->specificfeedback ?? '');
        $withoutplaceholders = preg_replace('~\[\[feedback:[A-Za-z0-9_]+\]\]~', '', $specific);
        if (trim(strip_tags($withoutplaceholders, '<img>')) !== '') {
            $feedback = $this->render_text($specific, $options->specificfeedbackformat ?? FORMAT_HTML,
                    $question, 'qtype_stack', 'specificfeedback', $question->id);
            $expout .= $this->section($this->str('specificfeedback'),
                    $this->stack_cas_markers($this->fill_stack_placeholders($feedback, $question)));
        }

        // Technical part, collapsed: variables, response trees, note, variants.
        $technical = '';
        if (trim((string) ($options->questionvariables ?? '')) !== '') {
            $technical .= $this->section($this->str('questionvariables'), '<pre>' . s($options->questionvariables) . '</pre>');
        }
        foreach ($this->stack_prts($question) as $name => $prt) {
            $content = '';
            if (trim((string) ($prt->feedbackvariables ?? '')) !== '') {
                $content .= '<table class="kv">' . $this->kv_row($this->str('feedbackvariables'),
                        '<pre>' . s($prt->feedbackvariables) . '</pre>') . "</table>\n";
            }
            $nodes = (array) ($prt->nodes ?? []);
            uasort($nodes, function ($a, $b) {
                return ((int) $a->nodename) <=> ((int) $b->nodename);
            });
            $rows = '';
            foreach ($nodes as $node) {
                $rows .= '<tr><td>' . ((int) $node->nodename + 1)
                        . (trim((string) ($node->description ?? '')) !== '' ? '<br><span class="muted">'
                            . s($node->description) . '</span>' : '')
                        . '</td><td>' . $this->stack_test_label($node) . '<br><code>' . s($node->sans ?? '')
                        . '</code> ⇔ <code>' . s($node->tans ?? '') . '</code></td>'
                        . '<td class="branch-true">' . $this->stack_branch($question, $node, 'true') . '</td>'
                        . '<td class="branch-false">' . $this->stack_branch($question, $node, 'false') . "</td></tr>\n";
            }
            if ($rows !== '') {
                $content .= '<table class="answers-table prt-table"><thead><tr><th>' . s($this->str('node')) . '</th><th>'
                        . s($this->str('answertest')) . '</th><th>' . s($this->str('iftrue')) . '</th><th>'
                        . s($this->str('iffalse')) . "</th></tr></thead>\n<tbody>\n$rows</tbody></table>\n";
            }
            $title = $this->str('prtn', $name);
            if (isset($prt->value)) {
                $title .= ' · ' . $this->str('weight', $this->number($prt->value));
            }
            $technical .= $this->section($title, $content, 'q-gap');
        }
        $note = strip_tags((string) ($options->questionnote ?? ''));
        if (preg_match('~[\p{L}\p{N}]~u', $note)) {
            $technical .= $this->section($this->str('questionnote'), '<code>' . $this->stack_cas_markers(s($note)) . '</code>');
        }
        if (!empty($question->deployedseeds)) {
            $technical .= '<p class="q-info">' . s($this->str('deployedvariants', count($question->deployedseeds))) . "</p>\n";
        }
        if ($technical !== '') {
            $expout .= '<details class="tech"><summary>' . s($this->str('technicaldetails')) . "</summary>\n"
                    . $technical . "</details>\n";
        }
        $expout .= '<p class="q-info muted">' . s($this->str('stacknotevaluated')) . "</p>\n";
        return $expout;
    }

    /**
     * Readable name of a STACK input type.
     *
     * @param string $type
     * @return string
     */
    protected function stack_input_type_label($type) {
        $key = 'stackinput_' . $type;
        return get_string_manager()->string_exists($key, 'qformat_xhtmlplus') ? $this->str($key) : $type;
    }

    /**
     * Readable name of a STACK answer test, with the original name and options.
     *
     * @param stdClass $node
     * @return string HTML
     */
    protected function stack_test_label($node) {
        $test = (string) ($node->answertest ?? '');
        $key = 'stacktest_' . strtolower($test);
        $label = get_string_manager()->string_exists($key, 'qformat_xhtmlplus')
                ? '<strong>' . s($this->str($key)) . '</strong> <span class="muted">(' . s($test) . ')</span>'
                : '<strong>' . s($test) . '</strong>';
        if (trim((string) ($node->testoptions ?? '')) !== '') {
            $label .= ' <code>' . s($node->testoptions) . '</code>';
        }
        return $label;
    }

    /**
     * One branch (true or false) of a STACK response tree node.
     *
     * @param stdClass $question
     * @param stdClass $node
     * @param string $branch true or false
     * @return string HTML
     */
    protected function stack_branch($question, $node, $branch) {
        $mode = $node->{$branch . 'scoremode'} ?? '=';
        $score = $node->{$branch . 'score'} ?? '';
        $score = is_numeric($score) ? $this->number($score) : (string) $score;
        $key = ['=' => 'scoreset', '+' => 'scoreadd', '-' => 'scoresub'][$mode] ?? 'scoreset';
        $out = '<span class="grade ' . ($branch === 'true' ? 'grade-correct' : 'grade-wrong') . '">'
                . s($this->str($key, $score)) . '</span>';
        $penalty = $node->{$branch . 'penalty'} ?? '';
        if ($penalty !== '' && $penalty !== null && (!is_numeric($penalty) || (float) $penalty != 0)) {
            $out .= ' <span class="muted">' . s($this->str('penaltyshort',
                    is_numeric($penalty) ? $this->number($penalty) : $penalty)) . '</span>';
        }
        $next = (int) ($node->{$branch . 'nextnode'} ?? -1);
        $out .= '<br>' . s($next >= 0 ? $this->str('nextnode', $next + 1) : $this->str('endtree'));
        // Answer notes such as "prt1-2-T" are generated automatically: show only the ones the author wrote.
        $note = trim((string) ($node->{$branch . 'answernote'} ?? ''));
        if ($note !== '' && !preg_match('~^[A-Za-z0-9_]+-\d+-[TF]$~', $note)) {
            $out .= '<br><span class="muted">' . s($this->str('answernote')) . ':</span> <code>' . s($note) . '</code>';
        }
        $feedback = (string) ($node->{$branch . 'feedback'} ?? '');
        if (trim(strip_tags($feedback, '<img>')) !== '') {
            $out .= '<div class="choice-feedback">' . $this->stack_cas_markers($this->render_text($feedback,
                    $node->{$branch . 'feedbackformat'} ?? FORMAT_HTML, $question, 'qtype_stack',
                    'prtnode' . $branch . 'feedback', $node->id ?? 0)) . '</div>';
        }
        return $out;
    }

    protected function presave_process($content) {
        global $CFG;

        $cssfile = "{$CFG->dirroot}/question/format/xhtmlplus/xhtml.css";
        $css = file_exists($cssfile) ? file_get_contents($cssfile) : '';
        $title = $this->str('exportedquestions');

        $summary = '';
        ksort($this->typecounts);
        foreach ($this->typecounts as $label => $count) {
            $summary .= '<span class="badge">' . s($label) . ' <b>' . $count . '</b></span>';
        }

        $toc = '';
        foreach ($this->index as $path => $entries) {
            $toc .= '<div class="toc-cat">' . s($path !== '' ? $path : '—') . "</div>\n<ol class=\"toc-list\">\n";
            foreach ($entries as [$anchor, $number, $name, $label]) {
                $toc .= '<li value="' . $number . '"><a href="#' . $anchor . '">' . s($name) . '</a> <span class="muted">'
                        . s($label) . "</span></li>\n";
            }
            $toc .= "</ol>\n";
        }

        $xp = "<!DOCTYPE html>\n";
        $xp .= '<html lang="' . s(current_language()) . "\">\n<head>\n";
        $xp .= "<meta charset=\"UTF-8\" />\n";
        $xp .= "<meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0\" />\n";
        $xp .= '<title>' . s($title) . "</title>\n";
        // TeX formulas are typeset by MathJax when the file is opened online; offline the TeX source stays visible.
        $xp .= '<script>window.MathJax = {tex: {inlineMath: [["\\\\(", "\\\\)"]], '
                . 'displayMath: [["$$", "$$"], ["\\\\[", "\\\\]"]]}};</script>' . "\n";
        $xp .= '<script async src="https://cdn.jsdelivr.net/npm/mathjax@3.2.2/es5/tex-chtml.js"></script>' . "\n";
        // Collapsed technical details are opened when printing.
        $xp .= '<script>window.addEventListener("beforeprint", function () {'
                . ' document.querySelectorAll("details.tech").forEach(function (d) { d.open = true; }); });</script>' . "\n";
        $xp .= "<style>\n" . $css . "\n</style>\n</head>\n<body>\n<div class=\"doc\">\n";
        $xp .= "<header class=\"doc-head\">\n<h1>" . s($title) . "</h1>\n";
        $xp .= '<p class="doc-meta">' . s($this->str('exportdate', userdate(time()))) . ' · '
                . s($this->str('questionscount', $this->questionnumber)) . "</p>\n";
        $xp .= '<div class="q-badges">' . $summary . "</div>\n</header>\n";
        if ($toc !== '') {
            $xp .= '<details class="toc"><summary>' . s($this->str('contents')) . "</summary>\n" . $toc . "</details>\n";
        }
        $xp .= "<main>\n" . $content . "</main>\n</div>\n</body>\n</html>\n";

        return $xp;
    }

    public function export_file_extension() {
        return '.html';
    }
}
