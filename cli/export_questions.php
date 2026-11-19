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
 * Export a list of questions, chosen by id from any category, to one file.
 *
 * Read only: it does not create or change questions.
 *
 * @package    qformat_xhtmlplus
 * @copyright  2026 Giovanni Licari, Alessandro Lorenzi, Claudio Codice
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

// Find config.php whatever the layout (classic, or Moodle 5.1+ with public/).
$dir = __DIR__;
while (!file_exists($dir . '/config.php') && dirname($dir) !== $dir) {
    $dir = dirname($dir);
}
require($dir . '/config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/questionlib.php');
require_once($CFG->dirroot . '/question/format.php');

[$options, $unrecognised] = cli_get_params([
    'ids' => '',
    'idsfile' => '',
    'output' => '',
    'format' => 'xml',
    'help' => false,
], [
    'h' => 'help',
]);

$help = <<<EOT
Export questions chosen by id (from any category) to a single file.

Options:
  --ids=1,2,3         Question ids, separated by commas or spaces.
  --idsfile=PATH      File with question ids (one per line, or comma separated).
  --output=PATH       File to write (required).
  --format=NAME       Question format: xml (default, Moodle XML) or xhtmlplus.
  -h, --help          Print this help.

Example:
  php question/format/xhtmlplus/cli/export_questions.php --ids=18600430,18605403 --output=/tmp/test.xml

Notes:
  - The ids are exported exactly as given (no switch to the latest version).
  - Hidden questions and cloze subquestions are skipped.
  - The Moodle XML file includes the questions' files (images, audio...) and can be
    imported into a test category.

EOT;

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}
if ($options['help']) {
    echo $help;
    exit(0);
}
if ($options['output'] === '') {
    cli_error("Missing --output.\n\n" . $help);
}

// Collect the ids, keeping the order given.
$rawids = $options['ids'];
if ($options['idsfile'] !== '') {
    if (!is_readable($options['idsfile'])) {
        cli_error('Cannot read ' . $options['idsfile']);
    }
    $rawids .= ' ' . file_get_contents($options['idsfile']);
}
$ids = array_values(array_unique(array_filter(array_map('intval', preg_split('~[^0-9]+~', $rawids)))));
if (!$ids) {
    cli_error("No question ids given.\n\n" . $help);
}

// Format plugin.
$format = clean_param($options['format'], PARAM_PLUGIN);
$formatfile = $CFG->dirroot . '/question/format/' . $format . '/format.php';
if ($format === '' || !is_readable($formatfile)) {
    cli_error('Unknown question format: ' . $options['format']);
}
require_once($formatfile);
$classname = 'qformat_' . $format;
$qformat = new $classname();
if (!$qformat->provide_export()) {
    cli_error("The format '$format' cannot export.");
}

// Run as admin, so file and filter code behaves as in the web interface.
if (class_exists('\core\cron')) {
    \core\cron::setup_user();
} else {
    cron_setup_user();
}

// Load questions with their type-specific options.
$loaded = question_load_questions($ids);
if (!is_array($loaded)) {
    cli_error('Error loading questions: ' . $loaded);
}

$questions = [];
foreach ($ids as $id) {
    if (!isset($loaded[$id])) {
        cli_writeln("Skipped $id: not found.");
        continue;
    }
    $question = $loaded[$id];
    if (!empty($question->parent)) {
        cli_writeln("Skipped $id: cloze subquestion (export its parent {$question->parent}).");
        continue;
    }
    if ($question->status === \core_question\local\bank\question_version_status::QUESTION_STATUS_HIDDEN) {
        cli_writeln("Skipped $id: hidden question.");
        continue;
    }
    $questions[] = $question;
}

if (!$questions) {
    cli_error('No questions to export.');
}

// Export one question at a time, so that a failing question is reported and skipped
// instead of stopping the whole export. This replaces exportprocess(), which works on
// a whole category and stops at the first error.
core_php_time_limit::raise();
raise_memory_limit(MEMORY_EXTRA);

// writequestion() and presave_process() are protected in some formats.
$invoke = Closure::bind(function ($method, ...$args) {
    return $this->$method(...$args);
}, $qformat, get_class($qformat));

$body = '';
$exported = 0;
$failed = [];
foreach ($questions as $question) {
    cli_writeln("Exporting {$question->id} ({$question->qtype})...");
    if (empty($question->contextid)) {
        $question->contextid = $DB->get_field('question_categories', 'contextid', ['id' => $question->category]);
    }
    $question->idnumber = $DB->get_field('question_bank_entries', 'idnumber', ['id' => $question->questionbankentryid]);
    // The XML format filters course tags by $this->course: without it, questions with
    // course tags fail. Use the course the question belongs to (or the site).
    $coursecontext = context::instance_by_id($question->contextid)->get_course_context(false);
    $qformat->setCourse($coursecontext ? get_course($coursecontext->instanceid) : get_site());
    try {
        $output = $invoke('writequestion', $question);
    } catch (Throwable $e) {
        $failed[$question->id] = $question->qtype;
        cli_writeln("  FAILED {$question->id} ({$question->qtype}): " . $e->getMessage()
                . ' [' . $e->getFile() . ':' . $e->getLine() . ']');
        continue;
    }
    if ($output !== null) {
        $body .= $output . "\n";
        $exported++;
    }
}

if (!$exported) {
    cli_error('No questions exported.');
}

$content = $invoke('presave_process', $body);
if (file_put_contents($options['output'], $content) === false) {
    cli_error('Cannot write ' . $options['output']);
}

cli_writeln('');
cli_writeln("Exported $exported of " . count($ids) . ' question(s) to ' . $options['output']);
if ($failed) {
    cli_writeln('Failed: ' . implode(', ', array_map(function ($id, $qtype) {
        return "$id ($qtype)";
    }, array_keys($failed), $failed)));
}
