# XHTML+ Question Export Format - Complete Solution

## Overview

L'esportatore **XHTML+ (xhtmlplus)** è stato completamente migliorato per supportare **TUTTE** le informazioni richieste per ogni domanda nel deposito domande di Moodle.

## Informazioni Esportate

Per ogni domanda, il formato XHTML+ esporta:

### 1. **Metadati della Domanda**
- ✅ ID domanda
- ✅ Nome domanda
- ✅ Tipo di domanda (qtype)
- ✅ Punteggio predefinito (defaultmark)

### 2. **Testo della Domanda**
- ✅ Testo della domanda formattato
- ✅ Formattazione preservata

### 3. **Risposte Corrette**
- ✅ Tutte le risposte possibili
- ✅ Indicazione visiva (✔) delle risposte corrette
- ✅ Frazione/peso della risposta

### 4. **Feedback**
- ✅ Feedback generale della domanda
- ✅ Feedback specifico per ogni risposta

### 5. **Tag**
- ✅ Associati alla domanda (quando disponibili)

### 6. **Punteggio**
- ✅ Punteggio predefinito
- ✅ Frazione per ogni risposta (per domande a scelta multipla)

## Tipi di Domande Supportati

Ogni domanda mostra il percorso della categoria, il tipo e il punteggio. In cima al file ci sono il riepilogo per tipo e l'indice.

| Tipo | Cosa mostra |
|---|---|
| Vero/Falso (truefalse) | Valore corretto e feedback di entrambe le risposte |
| Scelta multipla (multichoice) | Tutte le opzioni, corrette evidenziate, punteggio parziale e feedback |
| Risposta breve (shortanswer) | Tutte le risposte con punteggio e feedback, maiuscole/minuscole |
| Numerica (numerical) | Risposte con tolleranza, punteggio, feedback e unità |
| Abbinamento (match) | Ogni elemento con la sua risposta, più i distrattori |
| Componimento (essay) | Impostazioni della risposta, informazioni per i valutatori, modello |
| Cloze (multianswer) | Soluzioni dentro il testo e tabella di ogni spazio con tutte le risposte |
| Calcolate (calculated, calculatedsimple, calculatedmulti) | Formula, tolleranza, variabili con intervallo e primi valori generati (letti dal database) |
| Trascina nel testo (ddwtos), Seleziona parole mancanti (gapselect) | Soluzioni dentro il testo e tabella delle scelte con i distrattori |
| Trascina su immagine (ddimageortext), Marcatori (ddmarker) | Immagine di sfondo con le soluzioni disegnate sopra, più tabella delle zone |
| Ordinamento (ordering) | Elementi nell'ordine corretto |
| Formulas | Variabili casuali e globali, e per ogni parte caselle, risposta, tipo, criterio di correttezza spiegato a parole, unità e feedback (non calcolati) |
| Descrizione (description) | Solo il testo |
| STACK | Risposta del docente al posto di ogni campo (valore della variabile, opzione corretta dei menu), tabella dei campi e feedback specifico. Variabili, alberi di risposta (test e punteggi a parole), nota e varianti stanno in un riquadro "Dettagli tecnici" da aprire, che si apre da solo in stampa. Non viene eseguito Maxima: le espressioni calcolate compaiono come ⟨...⟩ |
| Altri tipi | Testo e feedback generale, con l'avviso "non implementato" |

Per tutti i tipi vengono mostrati anche il feedback combinato, i suggerimenti e il feedback generale. Le formule TeX vengono impaginate con MathJax quando il file viene aperto con una connessione a Internet.

## Struttura del File HTML Esportato

### Intestazione
```html
<!DOCTYPE html>
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Exported Questions with Complete Solutions</title>
  <style>/* CSS completo incluso */</style>
</head>
<body>
```

### Contenuto (per ogni domanda)
```html
<!-- Metadati -->
<div class="question-metadata">
  <div class="meta-row">
    <span class="meta-label">ID:</span>
    <span class="meta-value">42</span>
  </div>
  <!-- ... altri metadati ... -->
</div>

<!-- Testo domanda -->
<div class="question-text">
  <h3>Nome Domanda</h3>
  <div class="questiontext">Testo della domanda...</div>
</div>

<!-- Contenuto (risposte, feedback) -->
<div class="question-content">
  <!-- Dipende dal tipo di domanda -->
</div>

<!-- Feedback generale -->
<div class="general-feedback">
  <strong>Feedback generale:</strong>
  <div class="feedback-content">...</div>
</div>
```

## Stili CSS Inclusi

Il file CSS esportato include stili per:

- **Evidenziazione risposte corrette**: sfondo verde e badge ✔
- **Feedback**: box blu per feedback specifici
- **Metadati**: sezione grigiastra per informazioni
- **Design responsivo**: perfetto per mobile e desktop
- **Stampa**: ottimizzato per stampa su carta

### Colori Utilizzati
- 🟢 Verde (#27ae60): risposte corrette
- 🔵 Blu (#3498db): informazioni e feedback
- 🟡 Giallo (#ffc107): dati numerici

## Esempio di Utilizzo

### Come esportare le domande:

1. Vai alla **Banca delle Domande** di Moodle
2. Seleziona la **categoria** o le **domande** da esportare
3. Scegli il formato **"XHTML+ format with complete solutions"**
4. Clicca **"Esporta"**

Il file HTML risultante conterrà:
- ✅ Tutte le domande con metadati
- ✅ Tutte le risposte e il feedback
- ✅ Visuale professionale e stampabile
- ✅ Completamente autonomo (no dipendenze da Moodle)

## Vantaggi

| Vantaggi | Descrizione |
|----------|------------|
| 📋 **Completo** | Tutte le informazioni richieste |
| 🎨 **Visuale** | Design moderno e professionale |
| 📱 **Responsive** | Funziona su mobile e desktop |
| 🖨️ **Stampabile** | Perfetto per stampa |
| 🔍 **Leggibile** | Facile da leggere e comprendere |
| 🔒 **Autonomo** | Non necessita di connessione a Moodle |

## File Modificati

```
question/format/xhtmlplus/
├── format.php              ✅ Migliorato con supporto completo
├── xhtml.css               ✅ CSS moderno e responsivo
└── lang/en/
    └── qformat_xhtmlplus.php ✅ Stringhe aggiornate
```

Inoltre aggiornato:
```
question/format/xhtml/
├── format.php              ✅ Versione parallela migliora
├── xhtml.css               ✅ CSS completo
└── lang/en/
    └── qformat_xhtml.php   ✅ Stringhe aggiornate
```

## Caratteristiche Specifiche per Tipo di Domanda

### Vero/Falso
```
✔ Risposta: Vero
  Feedback: ...
```

### Scelta Multipla
```
✔ Risposta 1 (corretta)
   Feedback specifico
☐ Risposta 2
   Feedback specifico
```

### Risposta Breve
```
Input text: _________________
✔ Risposte corrette: "Giusta1" | "Giusta2"
Feedback: ...
```

### Numerica
```
Input text: _________________
✔ Risposta: 42 ±0.5
Feedback: ...
```

### Abbinamento
```
Domanda 1 → ✔ Risposta corretta 1
Domanda 2 → ✔ Risposta corretta 2
```

### Essay
```
Tipo di risposta: HTML Editor
Risposta richiesta: Sì/No
Allegati: numero consentito
```

### Multianswer (Cloze)
```
Primo buco: ✔ risposta corretta
Secondo buco: ✔ risposta corretta
Feedback per ogni buco
```

## Validazione

Tutti i file sono stati validati:
- ✅ Sintassi PHP corretta
- ✅ HTML valido
- ✅ CSS conforme agli standard

## Compatibilità

- ✅ Moodle 3.x e superiori
- ✅ Tutti i browser moderni
- ✅ Mobile-friendly
- ✅ Stampa supportata

## File e Contenuti Multimediali

Vale per testo della domanda, risposte, feedback (generale e delle risposte) e sottodomande dell'abbinamento:

- **Immagini caricate in Moodle** fino a 1 MB: incorporate nel file (base64), visibili offline e in stampa. Quelle più grandi restano un link al server Moodle.
- **Audio e video caricati in Moodle**: riquadro con nome e dimensione del file più il player del browser, che funziona solo se si è collegati a Moodle. In stampa resta solo il riquadro.
- **Link esterni a file audio/video** (es. `.mp3`, `.mp4`): riquadro con il link e il player del browser.
- **YouTube, Vimeo e altri contenuti incorporati** (iframe): solo il link, senza player.
- **Altri file** (PDF, documenti): link al server Moodle.

Il filtro multimediale di Moodle non viene applicato, perché produce player che richiedono JavaScript; gli altri filtri (es. multilingua) sì.

## Export da riga di comando per ID

`cli/export_questions.php` esporta in un unico file un elenco di domande scelte per ID, anche da categorie diverse. Non modifica nulla.

```bash
# Moodle XML completo (con immagini e altri file), da importare in una categoria di test
php question/format/xhtmlplus/cli/export_questions.php --ids=18600430,18605403 --output=/tmp/test.xml

# Direttamente in formato XHTML+
php question/format/xhtmlplus/cli/export_questions.php --idsfile=/tmp/ids.txt --format=xhtmlplus --output=/tmp/test.html
```

Con Moodle 5.1+ il percorso parte da `public/`. Le domande nascoste e le sottodomande del cloze vengono saltate.

## Note Importanti

1. **Locale**: Tutte le stringhe usano il sistema di localizzazione di Moodle
2. **Sicurezza**: HTML è escapato per prevenire XSS
3. **Performance**: CSS è inline nel documento per autonomia
4. **Accessibilità**: Struttura HTML semantica e ben formattata

---

**Versione**: 1.0.0
**Ultimo aggiornamento**: 2026
**Autori**: Giovanni Licari, Alessandro Lorenzi, Claudio Codice
