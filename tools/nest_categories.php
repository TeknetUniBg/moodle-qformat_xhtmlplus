<?php
// Redistribute the questions of a Moodle XML export into nested test categories (up to 4 levels).
//
// Usage: php nest_categories.php input.xml output.xml
//
// Import the result with "Get category from file" enabled.

[$script, $input, $output] = $argv + [null, null, null];
if (!$input || !$output) {
    fwrite(STDERR, "Usage: php nest_categories.php input.xml output.xml\n");
    exit(1);
}

$source = new DOMDocument();
if (!$source->load($input, LIBXML_PARSEHUGE)) {
    fwrite(STDERR, "Invalid XML: $input\n");
    exit(1);
}

// Base path: the category of the original file, or the course default.
$base = '$course$/top';
foreach ($source->getElementsByTagName('question') as $q) {
    if ($q->getAttribute('type') === 'category') {
        $base = trim($q->getElementsByTagName('category')->item(0)->textContent);
        break;
    }
}
$root = $base . '/Test xhtmlplus';

/**
 * Category path (relative to the test root) for a question.
 */
function category_for(DOMElement $q) {
    $type = $q->getAttribute('type');
    $name = trim($q->getElementsByTagName('name')->item(0)->textContent);
    $text = $q->getElementsByTagName('questiontext')->item(0)->textContent;
    $media = preg_match('~<audio|<video|<iframe|\.mp3|\.mp4|\.ogg|\.webm|youtu~i', $text);

    switch ($type) {
        case 'description':
            // One description stays in the root, to test a category with both questions and subcategories.
            return $name === 'DESC - Testo informativo' ? '' : 'Descrizioni';
        case 'multichoice':
        case 'truefalse':
            return 'Tipi base/Scelta e vero-falso';
        case 'shortanswer':
        case 'numerical':
        case 'essay':
            return 'Tipi base/Risposte aperte';
        case 'cloze':
            return $media ? 'Tipi base/Risposte aperte/Cloze/Cloze con audio e video' : 'Tipi base/Risposte aperte/Cloze';
        case 'ddwtos':
        case 'gapselect':
        case 'matching':
        case 'ordering':
            return 'Tipi base/Trascina e completa';
        case 'calculated':
        case 'calculatedsimple':
        case 'calculatedmulti':
        case 'formulas':
            return 'Calcolate e formule';
        case 'stack':
            $inputs = 0;
            foreach ($q->childNodes as $child) {
                if ($child instanceof DOMElement && $child->nodeName === 'input') {
                    $inputs++;
                }
            }
            return $inputs > 1 ? 'STACK/Più campi' : 'STACK';
        default:
            return 'Altri tipi';
    }
}

// Group questions by category, keeping the original order inside each group.
$groups = [];
foreach ($source->documentElement->childNodes as $q) {
    if (!($q instanceof DOMElement) || $q->nodeName !== 'question' || $q->getAttribute('type') === 'category') {
        continue;
    }
    $groups[category_for($q)][] = $q;
}
$order = ['', 'Descrizioni', 'Tipi base/Scelta e vero-falso', 'Tipi base/Risposte aperte',
    'Tipi base/Risposte aperte/Cloze', 'Tipi base/Risposte aperte/Cloze/Cloze con audio e video',
    'Tipi base/Trascina e completa', 'Calcolate e formule', 'STACK', 'STACK/Più campi', 'Altri tipi'];
uksort($groups, function ($a, $b) use ($order) {
    return array_search($a, $order) <=> array_search($b, $order);
});

$out = new DOMDocument('1.0', 'UTF-8');
$out->formatOutput = true;
$quiz = $out->appendChild($out->createElement('quiz'));
$total = 0;
foreach ($groups as $path => $questions) {
    $full = $path === '' ? $root : $root . '/' . $path;
    $cat = $out->createElement('question');
    $cat->setAttribute('type', 'category');
    $category = $cat->appendChild($out->createElement('category'));
    $category->appendChild($out->createElement('text'))->appendChild($out->createTextNode($full));
    $info = $cat->appendChild($out->createElement('info'));
    $info->setAttribute('format', 'html');
    $info->appendChild($out->createElement('text'));
    $cat->appendChild($out->createElement('idnumber'));
    $quiz->appendChild($out->createComment(' category: ' . $full . ' '));
    $quiz->appendChild($cat);
    foreach ($questions as $q) {
        $quiz->appendChild($out->importNode($q, true));
        $total++;
    }
    $depth = substr_count($full, '/') - substr_count($base, '/');
    printf("%-75s %2d question(s), level %d\n", $full, count($questions), $depth);
}
$out->save($output);
echo "Written $output with $total question(s).\n";
