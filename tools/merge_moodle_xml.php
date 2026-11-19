<?php
// Merge several Moodle XML question exports into a single file to import.
//
// Usage:
//   php merge_moodle_xml.php output.xml input1.xml input2.xml ...
//   php merge_moodle_xml.php output.xml folder/      (all .xml files in the folder)
//
// Category entries are dropped, so every question lands in the category chosen
// on import. Questions with the same <name> are kept: Moodle imports them as
// separate questions.

if ($argc < 3) {
    fwrite(STDERR, "Usage: php merge_moodle_xml.php output.xml input.xml|folder [...]\n");
    exit(1);
}

$output = $argv[1];
$inputs = [];
foreach (array_slice($argv, 2) as $arg) {
    if (is_dir($arg)) {
        $found = glob(rtrim($arg, '/\\') . '/*.xml');
        sort($found);
        $inputs = array_merge($inputs, $found);
    } else {
        $inputs[] = $arg;
    }
}
$inputs = array_values(array_filter($inputs, function ($file) use ($output) {
    return realpath($file) !== realpath($output);
}));

$merged = new DOMDocument('1.0', 'UTF-8');
$merged->formatOutput = true;
$quiz = $merged->appendChild($merged->createElement('quiz'));

$total = 0;
foreach ($inputs as $file) {
    $doc = new DOMDocument();
    if (!@$doc->load($file, LIBXML_PARSEHUGE)) {
        fwrite(STDERR, "Skipped (not valid XML): $file\n");
        continue;
    }
    $count = 0;
    foreach ($doc->getElementsByTagName('question') as $question) {
        // Only top-level questions (cloze subquestions are inside the text, not separate nodes).
        if ($question->parentNode->nodeName !== 'quiz' || $question->getAttribute('type') === 'category') {
            continue;
        }
        $quiz->appendChild($merged->createComment(' from ' . basename($file) . ' '));
        $quiz->appendChild($merged->importNode($question, true));
        $count++;
    }
    fwrite(STDERR, sprintf("%-50s %d question(s)\n", basename($file), $count));
    $total += $count;
}

$merged->save($output);
fwrite(STDERR, "Written $output with $total question(s) from " . count($inputs) . " file(s).\n");
