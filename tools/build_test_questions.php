<?php
// Generate Moodle XML files with test questions for qformat_xhtmlplus.
//
// Usage: php build_test_questions.php [output_dir]
//
// Writes:
//   test_questions_media.xml        core question types with files and media in every text area
//   test_questions_dd_ordering.xml  ddimageortext, ddmarker, ordering
//
// All questions are named "XP.." so they are easy to find after import.
// Files are generated here: small and large PNG, a WAV beep, a minimal PDF and a
// placeholder .mp4 (not a real video: it only tests how the export shows it).

$outdir = rtrim($argv[1] ?? __DIR__, '/\\');

// ---------------------------------------------------------------------------
// Generated files.
// ---------------------------------------------------------------------------

function png_label($width, $height, $label, $rgb) {
    $img = imagecreatetruecolor($width, $height);
    imagefill($img, 0, 0, imagecolorallocate($img, ...$rgb));
    $black = imagecolorallocate($img, 0, 0, 0);
    imagerectangle($img, 0, 0, $width - 1, $height - 1, $black);
    imagestring($img, 5, 10, (int) ($height / 2) - 8, $label, $black);
    ob_start();
    imagepng($img);
    return ob_get_clean();
}

function png_noise($width, $height) {
    mt_srand(42);
    $img = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            imagesetpixel($img, $x, $y, mt_rand(0, 0xFFFFFF));
        }
    }
    imagestring($img, 5, 10, 10, 'IMMAGINE GRANDE (> 1 MB)', imagecolorallocate($img, 255, 255, 255));
    ob_start();
    imagepng($img, null, 9);
    return ob_get_clean();
}

function png_background() {
    $img = imagecreatetruecolor(400, 250);
    imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 230));
    $black = imagecolorallocate($img, 0, 0, 0);
    imagerectangle($img, 20, 20, 180, 110, $black);
    imagestring($img, 4, 30, 30, 'Zona 1', $black);
    imagerectangle($img, 220, 140, 380, 230, $black);
    imagestring($img, 4, 230, 150, 'Zona 2', $black);
    imageellipse($img, 300, 60, 80, 80, $black);
    imagestring($img, 3, 280, 55, 'Cerchio', $black);
    ob_start();
    imagepng($img);
    return ob_get_clean();
}

function wav_beep() {
    $rate = 8000;
    $samples = '';
    for ($i = 0; $i < $rate; $i++) {
        $samples .= chr((int) (128 + 100 * sin(2 * M_PI * 440 * $i / $rate)));
    }
    $len = strlen($samples);
    return 'RIFF' . pack('V', 36 + $len) . 'WAVE'
        . 'fmt ' . pack('VvvVVvv', 16, 1, 1, $rate, $rate, 1, 8)
        . 'data' . pack('V', $len) . $samples;
}

function pdf_minimal() {
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R '
            . '/Resources << /Font << /F1 5 0 R >> >> >>',
        null,
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $stream = 'BT /F1 24 Tf 72 760 Td (Dispensa di prova XHTML+) Tj ET';
    $objects[3] = '<< /Length ' . strlen($stream) . " >>\nstream\n$stream\nendstream";
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n$object\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    return $pdf;
}

$FILES = [
    'immagine.png' => png_label(240, 120, 'IMMAGINE PICCOLA', [200, 225, 255]),
    'risposta_a.png' => png_label(160, 60, 'Risposta A', [210, 255, 210]),
    'risposta_b.png' => png_label(160, 60, 'Risposta B', [255, 220, 210]),
    'feedback.png' => png_label(200, 60, 'Immagine feedback', [255, 255, 200]),
    'grande.png' => png_noise(900, 700),
    'sfondo.png' => png_background(),
    'beep.wav' => wav_beep(),
    'dispensa.pdf' => pdf_minimal(),
    'video_finto.mp4' => "Placeholder: not a real MP4 video, used only to test the export.\n",
];

if (strlen($FILES['grande.png']) <= 1048576) {
    fwrite(STDERR, "Warning: grande.png is not larger than 1 MB.\n");
}

// External URLs used in the texts.
const YT = 'https://www.youtube.com/watch?v=aqz-KE-bpKQ';
const YT_EMBED = 'https://www.youtube.com/embed/aqz-KE-bpKQ';
const VIMEO = 'https://vimeo.com/1084537';
const EXT_MP3 = 'https://www.soundhelix.com/examples/mp3/SoundHelix-Song-1.mp3';
const EXT_MP4 = 'https://download.blender.org/peach/bigbuckbunny_movies/BigBuckBunny_320x180.mp4';
const MAP_EMBED = 'https://www.openstreetmap.org/export/embed.html?bbox=9.60,45.65,9.72,45.72';

// ---------------------------------------------------------------------------
// XML helpers.
// ---------------------------------------------------------------------------

function cdata($text) {
    return '<text><![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $text) . ']]></text>';
}

function files(array $names) {
    global $FILES;
    $out = '';
    foreach ($names as $name) {
        $out .= '<file name="' . $name . '" path="/" encoding="base64">' . base64_encode($FILES[$name]) . "</file>\n";
    }
    return $out;
}

function html($tag, $text, array $files = [], $attrs = 'format="html"') {
    return "<$tag $attrs>" . cdata($text) . files($files) . "</$tag>\n";
}

function header_xml($type, $name, $questiontext, array $qfiles = [], $generalfeedback = '', array $gffiles = [],
        $defaultgrade = 1) {
    return "<question type=\"$type\">\n"
        . '<name>' . cdata($name) . "</name>\n"
        . html('questiontext', $questiontext, $qfiles)
        . html('generalfeedback', $generalfeedback, $gffiles)
        . "<defaultgrade>$defaultgrade</defaultgrade>\n<penalty>0.3333333</penalty>\n<hidden>0</hidden>\n<idnumber></idnumber>\n";
}

function combined_feedback() {
    return html('correctfeedback', 'Risposta corretta.')
        . html('partiallycorrectfeedback', 'Risposta parzialmente corretta.')
        . html('incorrectfeedback', 'Risposta errata.')
        . "<shownumcorrect/>\n";
}

function answer($fraction, $text, $feedback = '', array $afiles = [], array $ffiles = [], $format = 'html', $extra = '') {
    return "<answer fraction=\"$fraction\" format=\"$format\">" . cdata($text) . files($afiles)
        . html('feedback', $feedback, $ffiles) . $extra . "</answer>\n";
}

function quiz(array $questions) {
    return "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<quiz>\n" . implode("\n", $questions) . "</quiz>\n";
}

$img = '<img src="@@PLUGINFILE@@/immagine.png" alt="immagine piccola" width="240" height="120">';
$big = '<img src="@@PLUGINFILE@@/grande.png" alt="immagine grande">';
$audiotag = '<audio controls="true"><source src="@@PLUGINFILE@@/beep.wav">beep</audio>';
$videotag = '<video controls="true" width="320"><source src="@@PLUGINFILE@@/video_finto.mp4">video</video>';

// ---------------------------------------------------------------------------
// File 1: core types with media.
// ---------------------------------------------------------------------------

$media = [];

$media[] = header_xml('description', 'XP01 Descrizione - immagine piccola e grande',
        "<p>Immagine piccola (deve essere incorporata):</p><p>$img</p>"
        . "<p>Immagine oltre 1 MB (deve restare un link al server):</p><p>$big</p>",
        ['immagine.png', 'grande.png'], '', [], 0) . "</question>\n";

$media[] = header_xml('description', 'XP02 Descrizione - audio caricato',
        "<p>Audio con tag audio:</p><p>$audiotag</p>"
        . '<p>Audio come link: <a href="@@PLUGINFILE@@/beep.wav">ascolta il beep</a></p>',
        ['beep.wav'], '<p>Feedback generale con audio: ' . $audiotag . '</p>', ['beep.wav'], 0) . "</question>\n";

$media[] = header_xml('description', 'XP03 Descrizione - video caricato (file finto)',
        "<p>Video con tag video (il file non è un vero video, serve solo a vedere il riquadro):</p><p>$videotag</p>",
        ['video_finto.mp4'], '', [], 0) . "</question>\n";

$media[] = header_xml('description', 'XP04 Descrizione - PDF allegato',
        '<p>Link a un PDF caricato: <a href="@@PLUGINFILE@@/dispensa.pdf">dispensa</a> (deve restare un link al server).</p>',
        ['dispensa.pdf'], '', [], 0) . "</question>\n";

$media[] = header_xml('description', 'XP05 Descrizione - link esterni',
        '<ul>'
        . '<li>YouTube: <a href="' . YT . '">video su YouTube</a></li>'
        . '<li>Vimeo: <a href="' . VIMEO . '">' . VIMEO . '</a></li>'
        . '<li>MP3 esterno: <a href="' . EXT_MP3 . '">brano mp3</a></li>'
        . '<li>MP4 esterno: <a href="' . EXT_MP4 . '">video mp4</a></li>'
        . '<li>Link normale (deve restare un link normale): <a href="https://www.unibg.it">sito</a></li>'
        . '</ul>', [], '', [], 0) . "</question>\n";

$media[] = header_xml('description', 'XP06 Descrizione - contenuti incorporati (iframe)',
        '<p>YouTube incorporato:</p><p><iframe width="560" height="315" src="' . YT_EMBED . '" allowfullscreen></iframe></p>'
        . '<p>Mappa incorporata:</p><p><iframe width="425" height="350" src="' . MAP_EMBED . '"></iframe></p>',
        [], '', [], 0) . "</question>\n";

$media[] = header_xml('multichoice', 'XP07 Scelta multipla - immagini nelle risposte e nei feedback',
        "<p>Quale immagine è la risposta A?</p><p>$img</p>", ['immagine.png'],
        '<p>Feedback generale con immagine:</p><p><img src="@@PLUGINFILE@@/feedback.png" alt="feedback"></p>', ['feedback.png'])
    . "<single>true</single>\n<shuffleanswers>true</shuffleanswers>\n<answernumbering>abc</answernumbering>\n"
    . "<showstandardinstruction>0</showstandardinstruction>\n" . combined_feedback()
    . answer(100, '<p><img src="@@PLUGINFILE@@/risposta_a.png" alt="A"></p>',
        '<p>Giusto. Immagine nel feedback: <img src="@@PLUGINFILE@@/feedback.png" alt="feedback"></p>',
        ['risposta_a.png'], ['feedback.png'])
    . answer(0, '<p><img src="@@PLUGINFILE@@/risposta_b.png" alt="B"></p>',
        '<p>Sbagliato. Ascolta: ' . $audiotag . '</p>', ['risposta_b.png'], ['beep.wav'])
    . answer(0, '<p>Formula: $$\\frac{a}{b} &lt; 1$$</p>', '<p>Risposta con formula TeX.</p>')
    . "</question>\n";

$media[] = header_xml('multichoice', 'XP08 Scelta multipla (più risposte) - YouTube nelle risposte, punteggi parziali',
        '<p>Quali sono corrette? (due risposte da 50%, una da -50%)</p>')
    . "<single>false</single>\n<shuffleanswers>false</shuffleanswers>\n<answernumbering>123</answernumbering>\n"
    . "<showstandardinstruction>1</showstandardinstruction>\n" . combined_feedback()
    . answer(50, '<p>Corretta 1, con <a href="' . YT . '">video YouTube</a></p>', '<p>Mezzo punto.</p>')
    . answer(50, '<p>Corretta 2</p>', '<p>Mezzo punto.</p>')
    . answer(-50, '<p>Sbagliata</p>', '<p>Penalità.</p>')
    . "</question>\n";

$media[] = header_xml('truefalse', 'XP09 Vero/Falso - video nel feedback generale',
        '<p>Il cielo è blu.</p>', [],
        "<p>Video nel feedback generale:</p><p>$videotag</p>", ['video_finto.mp4'])
    . answer(100, 'true', '<p>Esatto, è vero.</p>', [], [], 'moodle_auto_format')
    . answer(0, 'false', '<p>No, è vero. ' . $audiotag . '</p>', [], ['beep.wav'], 'moodle_auto_format')
    . "</question>\n";

$media[] = header_xml('shortanswer', 'XP10 Risposta breve - audio nel testo, immagine nel feedback',
        "<p>Ascolta e scrivi la parola:</p><p>$audiotag</p>", ['beep.wav'])
    . "<usecase>0</usecase>\n"
    . answer(100, 'beep', '<p>Giusto. <img src="@@PLUGINFILE@@/feedback.png" alt="feedback"></p>', [], ['feedback.png'],
        'moodle_auto_format')
    . answer(50, 'bip', '<p>Quasi: vale metà.</p>', [], [], 'moodle_auto_format')
    . answer(0, '*', '<p>Sbagliato.</p>', [], [], 'moodle_auto_format')
    . "</question>\n";

$media[] = header_xml('numerical', 'XP11 Numerica - immagine e tolleranza',
        "<p>Quanto vale 6 × 7? $img</p>", ['immagine.png'])
    . answer(100, '42', '<p>Giusto.</p>', [], [], 'moodle_auto_format', "<tolerance>0.5</tolerance>\n")
    . answer(50, '40', '<p>Vicino.</p>', [], [], 'moodle_auto_format', "<tolerance>1</tolerance>\n")
    . "<units></units>\n<unitgradingtype>0</unitgradingtype>\n<unitpenalty>0.1000000</unitpenalty>\n"
    . "<showunits>3</showunits>\n<unitsleft>0</unitsleft>\n"
    . "</question>\n";

$media[] = header_xml('match', 'XP12 Abbinamento - immagini e audio nelle sottodomande',
        '<p>Abbina ogni elemento alla risposta giusta.</p>')
    . "<shuffleanswers>true</shuffleanswers>\n" . combined_feedback()
    . '<subquestion format="html">' . cdata('<p><img src="@@PLUGINFILE@@/risposta_a.png" alt="A"></p>')
        . files(['risposta_a.png']) . '<answer>' . cdata('Lettera A') . "</answer></subquestion>\n"
    . '<subquestion format="html">' . cdata('<p><img src="@@PLUGINFILE@@/risposta_b.png" alt="B"></p>')
        . files(['risposta_b.png']) . '<answer>' . cdata('Lettera B') . "</answer></subquestion>\n"
    . '<subquestion format="html">' . cdata('<p>Suono: ' . $audiotag . '</p>')
        . files(['beep.wav']) . '<answer>' . cdata('Beep') . "</answer></subquestion>\n"
    . '<subquestion format="html">' . cdata('') . '<answer>' . cdata('Distrattore') . "</answer></subquestion>\n"
    . "</question>\n";

$media[] = header_xml('essay', 'XP13 Componimento - immagine e video, informazioni per il valutatore',
        "<p>Descrivi l'immagine e il video.</p><p>$img</p><p>$videotag</p>", ['immagine.png', 'video_finto.mp4'], '', [], 5)
    . "<responseformat>editor</responseformat>\n<responserequired>1</responserequired>\n"
    . "<responsefieldlines>10</responsefieldlines>\n<minwordlimit></minwordlimit>\n<maxwordlimit></maxwordlimit>\n"
    . "<attachments>1</attachments>\n<attachmentsrequired>0</attachmentsrequired>\n<maxbytes>0</maxbytes>\n"
    . "<filetypeslist></filetypeslist>\n"
    . html('graderinfo', '<p>Per il valutatore: deve citare il colore azzurro.</p>')
    . html('responsetemplate', '<p>Scrivi qui...</p>')
    . "</question>\n";

$media[] = header_xml('cloze', 'XP14 Cloze - audio, YouTube, iframe, immagine',
        "<p>Ascolta: $audiotag</p>"
        . '<p>Il suono è un {1:SHORTANSWER:=beep#Giusto~%50%bip#Quasi~*#No}.</p>'
        . '<p>Guarda <a href="' . YT . '">il video</a> e scegli: {1:MULTICHOICE:=coniglio#Esatto~gatto#No~cane#No}.</p>'
        . '<p><iframe width="425" height="350" src="' . MAP_EMBED . '"></iframe></p>'
        . "<p>$img Quanto fa 2+2? {2:NUMERICAL:=4:0#Giusto}</p>",
        ['beep.wav', 'immagine.png'], '<p>Feedback generale del cloze.</p>')
    . "</question>\n";

$media[] = header_xml('ddwtos', 'XP15 Trascina nel testo',
        '<p>Il [[1]] abbaia e il [[2]] miagola.</p>')
    . "<shuffleanswers>1</shuffleanswers>\n" . combined_feedback()
    . '<dragbox>' . cdata('cane') . "<group>1</group></dragbox>\n"
    . '<dragbox>' . cdata('gatto') . "<group>1</group></dragbox>\n"
    . '<dragbox>' . cdata('pesce') . "<group>1</group></dragbox>\n"
    . "</question>\n";

$media[] = header_xml('gapselect', 'XP16 Seleziona parola mancante',
        '<p>Roma è la capitale d\'[[1]] e Parigi della [[2]].</p>')
    . "<shuffleanswers>1</shuffleanswers>\n" . combined_feedback()
    . '<selectoption>' . cdata('Italia') . "<group>1</group></selectoption>\n"
    . '<selectoption>' . cdata('Francia') . "<group>1</group></selectoption>\n"
    . '<selectoption>' . cdata('Spagna') . "<group>1</group></selectoption>\n"
    . "</question>\n";

// ---------------------------------------------------------------------------
// File 2: drag and drop on image, ordering.
// ---------------------------------------------------------------------------

$dd = [];

$dd[] = header_xml('ddimageortext', 'XP17 Trascina su immagine',
        '<p>Trascina le etichette nelle zone giuste.</p>')
    . "<shuffleanswers/>\n" . combined_feedback()
    . files(['sfondo.png'])
    . '<drag><no>1</no>' . cdata('Etichetta 1') . "<draggroup>1</draggroup></drag>\n"
    . '<drag><no>2</no>' . cdata('Etichetta 2') . "<draggroup>1</draggroup></drag>\n"
    . '<drop>' . cdata('Zona 1') . "<no>1</no><choice>1</choice><xleft>40</xleft><ytop>60</ytop></drop>\n"
    . '<drop>' . cdata('Zona 2') . "<no>2</no><choice>2</choice><xleft>240</xleft><ytop>180</ytop></drop>\n"
    . "</question>\n";

$dd[] = header_xml('ddmarker', 'XP18 Marcatori su immagine',
        '<p>Posiziona i marcatori.</p>')
    . "<showmisplaced/>\n" . combined_feedback()
    . files(['sfondo.png'])
    . '<drag><no>1</no>' . cdata('Rettangolo') . "<noofdrags>1</noofdrags></drag>\n"
    . '<drag><no>2</no>' . cdata('Cerchio') . "<noofdrags>1</noofdrags></drag>\n"
    . "<drop><no>1</no><shape>rectangle</shape><coords>20,20;160,90</coords><choice>1</choice></drop>\n"
    . "<drop><no>2</no><shape>circle</shape><coords>300,60;40</coords><choice>2</choice></drop>\n"
    . "</question>\n";

$dd[] = header_xml('ordering', 'XP19 Ordinamento',
        '<p>Metti in ordine cronologico.</p>')
    . "<layouttype>VERTICAL</layouttype>\n<selecttype>ALL</selecttype>\n<selectcount>0</selectcount>\n"
    . "<gradingtype>ABSOLUTE_POSITION</gradingtype>\n<showgrading>SHOW</showgrading>\n"
    . "<numberingstyle>none</numberingstyle>\n" . combined_feedback()
    . answer('1.0000000', '<p>Fondazione di Roma</p>')
    . answer('2.0000000', '<p>Caduta dell\'Impero romano d\'Occidente</p>')
    . answer('3.0000000', '<p>Scoperta dell\'America</p>')
    . answer('4.0000000', '<p>Unità d\'Italia</p>')
    . "</question>\n";

// ---------------------------------------------------------------------------
// Write and validate.
// ---------------------------------------------------------------------------

foreach (['test_questions_media.xml' => $media, 'test_questions_dd_ordering.xml' => $dd] as $filename => $questions) {
    $xml = quiz($questions);
    $doc = new DOMDocument();
    if (!$doc->loadXML($xml)) {
        fwrite(STDERR, "Invalid XML: $filename\n");
        exit(1);
    }
    file_put_contents("$outdir/$filename", $xml);
    printf("%-34s %2d questions  %s\n", $filename, $doc->getElementsByTagName('question')->length,
        round(strlen($xml) / 1024) . ' KB');
}
