<?php
// ----------------------------------------------------
// GESTIONE SUBMIT FORM MAPPING
// ----------------------------------------------------
$template_id = [glo_template_id];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mapping_json'])) {
    $mapping_json = isset($_POST['mapping_json']) ? $_POST['mapping_json'] : '';
    $mapping_json_db = addslashes($mapping_json);
    $template_id_db = sc_sql_injection($template_id);
    $sql = "
        UPDATE template
           SET form_fields = '$mapping_json_db'
         WHERE id = $template_id_db
    ";
    sc_exec_sql($sql);
    sc_commit_trans();
    sc_redir('grid_template_istituti', "", "_self");
}

// Carica form_fields dal DB (posizioni salvate)
$pdf_url      = '';
$existingJson = '';
if (!empty($template_id)) {
    sc_select(templateRs, "SELECT form_fields FROM template WHERE id = " . (int)$template_id);
    $form_fields_raw = null;
    if (isset($templateRs) && $templateRs) {
        if (isset($templateRs->fields[0])) {
            $form_fields_raw = $templateRs->fields[0];
        } elseif (isset($templateRs->fields[1])) {
            $form_fields_raw = $templateRs->fields[1];
        } elseif (isset($templateRs->field) && isset($templateRs->field['form_fields'])) {
            $form_fields_raw = $templateRs->field['form_fields'];
        } elseif (isset($templateRs->row) && is_array($templateRs->row) && isset($templateRs->row['form_fields'])) {
            $form_fields_raw = $templateRs->row['form_fields'];
        } elseif (is_array($templateRs) && isset($templateRs[0]['form_fields'])) {
            $form_fields_raw = $templateRs[0]['form_fields'];
        } elseif (is_array($templateRs) && isset($templateRs[0][0])) {
            $form_fields_raw = $templateRs[0][0];
        }
        if ($form_fields_raw !== null && trim((string)$form_fields_raw) !== '') {
            $existingJson = $form_fields_raw;
        }
    }
}

if (!isset($pdf_url) || empty($pdf_url)) {
    $pdf_url = 'http://dms.dw1cloud.eu:8092/scriptcase/app/euroansa/template_download?templateId=' . $template_id;
}
if (!isset($existingJson)) {
    $existingJson = '';
}

$existingJsonForJs = ($existingJson === '' || $existingJson === null) ? 'null' : json_encode($existingJson);
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>PDF Mapping Tool</title>

    <style>
        /* Layout generale */
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            color: #333;
        }

        .top-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 16px;
            background-color: #222;
            color: #fff;
        }

        .top-bar-left {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .top-bar button {
            padding: 4px 10px;
            border-radius: 4px;
            border: 1px solid #555;
            background-color: #444;
            color: #fff;
            cursor: pointer;
            font-size: 13px;
        }

        .top-bar button:disabled {
            opacity: 0.4;
            cursor: not-allowed;
        }

        .top-bar .page-label {
            margin-left: 8px;
            font-size: 13px;
        }

        .top-bar .actions {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .top-bar .save-button {
            background-color: #1e88e5;
            border-color: #1565c0;
        }

        .top-bar .save-button:hover {
            background-color: #1976d2;
        }

        .top-bar .status-text {
            font-size: 12px;
            color: #ddd;
        }

        .main-layout {
            display: flex;
            height: calc(100vh - 44px);
            box-sizing: border-box;
        }

        .sidebar {
            width: 260px;
            padding: 12px;
            background-color: #fafafa;
            border-right: 1px solid #ddd;
            box-sizing: border-box;
            overflow-y: auto;
        }

        .sidebar h3 {
            margin: 0 0 8px 0;
            font-size: 14px;
            font-weight: bold;
        }

        .field-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .field-template {
            padding: 6px 8px;
            border-radius: 4px;
            border: 1px solid #ccc;
            background-color: #fff;
            font-size: 13px;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .field-template:hover {
            background-color: #f0f0f0;
        }

        .field-template .field-name {
            font-weight: 600;
        }

        .field-template .field-type {
            font-size: 11px;
            color: #666;
        }

        .pdf-panel {
            flex: 1;
            display: flex;
            /* allinea il PDF in alto invece che centrarlo verticalmente */
            align-items: flex-start;
            justify-content: center;
            overflow: auto;
            /* un po' di padding in alto per staccare il PDF dalla top bar di Scriptcase */
            padding: 24px 12px 12px;
            box-sizing: border-box;
        }

        .pdf-inner {
            position: relative;
            box-shadow: 0 0 8px rgba(0, 0, 0, 0.25);
            background-color: #fff;
        }

        #pdfCanvas {
            display: block;
            background-color: #fff;
        }

        #pdfOverlay {
            position: absolute;
            left: 0;
            top: 0;
            /* pointer-events abilitati anche per il container */
        }

        .pdf-field {
            position: absolute;
            border: 1px solid #1e88e5;
            background-color: rgba(30, 136, 229, 0.1);
            color: #0d47a1;
            font-size: 11px;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            padding: 2px 4px;
            pointer-events: auto;
            overflow: hidden;
            border-radius: 2px;
            /* permettiamo campi molto piccoli (pochi caratteri / font piccolo) */
            min-width: 2px;
            min-height: 2px;
        }

        .pdf-field .field-label {
            white-space: nowrap;
            text-overflow: ellipsis;
            overflow: hidden;
        }

        .pdf-field .field-type-badge {
            margin-left: 4px;
            font-size: 10px;
            color: #555;
        }

        /* Gestione dei cursori per drag & resize */
        .pdf-field.interact-resizable {
            box-sizing: border-box;
        }

        .pdf-field::after {
            content: "";
            position: absolute;
            right: 0;
            bottom: 0;
            width: 8px;
            height: 8px;
            border-right: 2px solid #1e88e5;
            border-bottom: 2px solid #1e88e5;
            box-sizing: border-box;
            cursor: se-resize;
        }

        .helper-text {
            margin-top: 8px;
            font-size: 11px;
            color: #666;
        }
    </style>
</head>
<body>

<form method="post" id="mappingForm">
    <div class="top-bar">
        <div class="top-bar-left">
            <button type="button" id="btnPrev">&laquo;</button>
            <button type="button" id="btnNext">&raquo;</button>
            <span class="page-label" id="pageLabel">Pagina 0 / 0</span>
        </div>
        <div class="actions">
            <span class="status-text" id="statusText"></span>
            <button type="button" class="save-button" id="btnSave">Salva</button>
        </div>
    </div>

    <div class="main-layout">
        <div class="sidebar">
            <h3>Campi disponibili</h3>
            <div id="fieldList" class="field-list"></div>
            <div class="helper-text">
                Clicca su un campo per aggiungerlo alla pagina corrente, poi trascinalo e ridimensionalo sopra il PDF.
            </div>
        </div>

        <div class="pdf-panel">
            <div class="pdf-inner">
                <canvas id="pdfCanvas"></canvas>
                <div id="pdfOverlay"></div>
            </div>
        </div>
    </div>

    <input type="hidden" name="mapping_json" id="mapping_json" value="">
    <input type="hidden" name="pdf_url" id="pdf_url" value="<?php echo htmlspecialchars($pdf_url, ENT_QUOTES, 'UTF-8'); ?>">
</form>

<!-- pdf.js (core) - usare una versione UMD (non ES module) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
<!-- interact.js -->
<script src="https://cdn.jsdelivr.net/npm/interactjs@1.10.11/dist/interact.min.js"></script>

<script>
    // --------------------------------------------------------------------
    // Configurazione PDF.js
    // --------------------------------------------------------------------
    // Imposta il worker di pdf.js dalla stessa versione usata sopra.
    if (typeof pdfjsLib !== 'undefined') {
        pdfjsLib.GlobalWorkerOptions.workerSrc =
            'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
    }

    // URL del PDF passato da PHP / Scriptcase
    var PDF_URL = document.getElementById('pdf_url').value;

    // Mapping esistente (modalità EDIT): da DB (form_fields)
    var existingMappingArray = <?php echo $existingJsonForJs; ?>;
    if (typeof existingMappingArray === 'string') {
        try { existingMappingArray = JSON.parse(existingMappingArray); } catch (e) { existingMappingArray = null; }
    }

    // --------------------------------------------------------------------
    // Stato globale
    // --------------------------------------------------------------------
    var pdfDoc = null;
    var currentPage = 1;
    var totalPages = 0;

    // Metriche per pagina:
    // pageMetrics[page] = { mmWidth, mmHeight, pxPerMmX, pxPerMmY }
    var pageMetrics = {};

    // Struttura per i campi per pagina:
    // pageFields[page] = [ { id, basePlaceholder, placeholder_name, type_id, page, xPx, yPx, widthPx, heightPx }, ... ]
    var pageFields = {};

    // Mappa ID -> istanza
    var fieldInstancesById = {};

    // Contatori per generare suffissi _idx_n
    var placeholderCounters = {};

    // Id incrementale per istanze
    var lastInstanceId = 0;

    // Scala fissa per il rendering PDF
    var FIXED_SCALE = 1.3;

    // Riferimenti DOM
    var canvas = document.getElementById('pdfCanvas');
    var overlay = document.getElementById('pdfOverlay');
    var pageLabel = document.getElementById('pageLabel');
    var statusText = document.getElementById('statusText');
    var fieldListEl = document.getElementById('fieldList');
    var btnPrev = document.getElementById('btnPrev');
    var btnNext = document.getElementById('btnNext');
    var btnSave = document.getElementById('btnSave');
    var formEl = document.getElementById('mappingForm');

    var canvasContext = canvas.getContext('2d');

    // --------------------------------------------------------------------
    // Campi predefiniti (lista statica)
    // --------------------------------------------------------------------
    var fieldTemplates = [
        { id: 'nome_richiedente', placeholder_name: 'nome_richiedente', type_id: 1 },
        { id: 'cognome_richiedente', placeholder_name: 'cognome_richiedente', type_id: 1 },
        { id: 'data_nascita', placeholder_name: 'data_nascita', type_id: 1 },
        { id: 'flag_consenso', placeholder_name: 'flag_consenso', type_id: 2 },
        { id: 'importo', placeholder_name: 'importo', type_id: 1 }
    ];

    // --------------------------------------------------------------------
    // Helper
    // --------------------------------------------------------------------
    function generateInstanceId() {
        lastInstanceId += 1;
        return 'fld_' + lastInstanceId;
    }

    function roundMm(value) {
        return Math.round(value * 100) / 100; // 2 decimali
    }

    function updateStatus(message) {
        statusText.textContent = message || '';
    }

    function updatePageLabel() {
        pageLabel.textContent = 'Pagina ' + currentPage + ' / ' + totalPages;
        btnPrev.disabled = (currentPage <= 1);
        btnNext.disabled = (currentPage >= totalPages);
    }

    function ensurePageArray(page) {
        if (!pageFields[page]) {
            pageFields[page] = [];
        }
    }

    // --------------------------------------------------------------------
    // Rendering lista campi in sidebar
    // --------------------------------------------------------------------
    function renderFieldTemplates() {
        fieldListEl.innerHTML = '';
        for (var i = 0; i < fieldTemplates.length; i++) {
            var tpl = fieldTemplates[i];
            var item = document.createElement('div');
            item.className = 'field-template';
            item.setAttribute('data-template-id', tpl.id);

            var nameSpan = document.createElement('span');
            nameSpan.className = 'field-name';
            nameSpan.textContent = tpl.placeholder_name;

            var typeSpan = document.createElement('span');
            typeSpan.className = 'field-type';
            typeSpan.textContent = 'type_id: ' + tpl.type_id;

            item.appendChild(nameSpan);
            item.appendChild(typeSpan);

            item.addEventListener('click', function (evt) {
                var templateId = this.getAttribute('data-template-id');
                addFieldFromTemplate(templateId);
            });

            fieldListEl.appendChild(item);
        }
    }

    function getTemplateById(templateId) {
        for (var i = 0; i < fieldTemplates.length; i++) {
            if (fieldTemplates[i].id === templateId) {
                return fieldTemplates[i];
            }
        }
        return null;
    }

    function getTemplateByPlaceholder(basePlaceholder) {
        for (var i = 0; i < fieldTemplates.length; i++) {
            if (fieldTemplates[i].placeholder_name === basePlaceholder) {
                return fieldTemplates[i];
            }
        }
        return null;
    }

    // --------------------------------------------------------------------
    // Aggiunta e rendering dei campi sulla pagina corrente
    // --------------------------------------------------------------------
    function addFieldFromTemplate(templateId) {
        var tpl = getTemplateById(templateId);
        if (!tpl) {
            return;
        }

        var baseName = tpl.placeholder_name;
        var idx = (placeholderCounters[baseName] || 0) + 1;
        placeholderCounters[baseName] = idx;

        var finalPlaceholder = baseName + '_idx_' + idx;

        // Se le metriche della pagina non sono ancora state calcolate,
        // usiamo un fallback approssimativo basato su ~96 DPI (3.78 px/mm).
        var metrics = pageMetrics[currentPage];
        if (!metrics) {
            var approxPxPerMm = 3.78;
            metrics = {
                mmWidth: 0,
                mmHeight: 0,
                pxPerMmX: approxPxPerMm,
                pxPerMmY: approxPxPerMm
            };
        }

        // Dimensioni predefinite: 100mm x 6mm
        var defaultWidthMm = 100;
        var defaultHeightMm = 6;
        var widthPx = defaultWidthMm * metrics.pxPerMmX;
        var heightPx = defaultHeightMm * metrics.pxPerMmY;
        // Margine leggermente più basso per non sovrapporsi al bordo superiore del PDF
        var marginX = 10;
        var marginY = 40;

        var instance = {
            id: generateInstanceId(),
            basePlaceholder: baseName,
            placeholder_name: finalPlaceholder,
            type_id: tpl.type_id,
            page: currentPage,
            xPx: marginX,
            yPx: marginY,
            widthPx: widthPx,
            heightPx: heightPx
        };

        ensurePageArray(currentPage);
        pageFields[currentPage].push(instance);
        fieldInstancesById[instance.id] = instance;

        renderFieldInstance(instance);
    }

    function renderFieldInstance(instance) {
        if (instance.page !== currentPage) {
            // Verrà renderizzato quando si naviga su quella pagina
            return;
        }

        var el = document.createElement('div');
        el.className = 'pdf-field interact-resizable';
        el.setAttribute('data-id', instance.id);

        // Posizionamento iniziale
        el.style.left = '0px';
        el.style.top = '0px';
        el.style.width = instance.widthPx + 'px';
        el.style.height = instance.heightPx + 'px';

        // Usiamo transform per il drag; x/y vengono salvati come data-x/data-y
        el.style.transform = 'translate(' + instance.xPx + 'px, ' + instance.yPx + 'px)';
        el.setAttribute('data-x', instance.xPx);
        el.setAttribute('data-y', instance.yPx);

        var labelSpan = document.createElement('span');
        labelSpan.className = 'field-label';
        labelSpan.textContent = instance.placeholder_name;

        var tpl = getTemplateByPlaceholder(instance.basePlaceholder);
        var typeId = instance.type_id;
        if (tpl && typeof tpl.type_id !== 'undefined') {
            typeId = tpl.type_id;
        }

        var typeSpan = document.createElement('span');
        typeSpan.className = 'field-type-badge';
        typeSpan.textContent = '(' + typeId + ')';

        el.appendChild(labelSpan);
        el.appendChild(typeSpan);

        overlay.appendChild(el);
    }

    function clearOverlay() {
        while (overlay.firstChild) {
            overlay.removeChild(overlay.firstChild);
        }
    }

    function renderFieldsForCurrentPage() {
        clearOverlay();
        ensurePageArray(currentPage);
        var list = pageFields[currentPage];
        for (var i = 0; i < list.length; i++) {
            renderFieldInstance(list[i]);
        }
    }

    function syncInstanceFromElement(el) {
        var id = el.getAttribute('data-id');
        var instance = fieldInstancesById[id];
        if (!instance) {
            return;
        }

        var overlayRect = overlay.getBoundingClientRect();
        var rect = el.getBoundingClientRect();

        instance.xPx = rect.left - overlayRect.left;
        instance.yPx = rect.top - overlayRect.top;
        instance.widthPx = rect.width;
        instance.heightPx = rect.height;
    }

    // --------------------------------------------------------------------
    // PDF: calcolo metriche e rendering pagine
    // --------------------------------------------------------------------
    function computeAllPageMetrics() {
        var promises = [];
        for (var p = 1; p <= pdfDoc.numPages; p++) {
            (function (pageNum) {
                var pr = pdfDoc.getPage(pageNum).then(function (page) {
                    var viewport1 = page.getViewport({ scale: 1.0 });
                    var viewportScaled = page.getViewport({ scale: FIXED_SCALE });

                    // viewport1.width/height sono in "punti PDF" (72 DPI)
                    var mmWidth = viewport1.width * 25.4 / 72.0;
                    var mmHeight = viewport1.height * 25.4 / 72.0;

                    var pxPerMmX = viewportScaled.width / mmWidth;
                    var pxPerMmY = viewportScaled.height / mmHeight;

                    pageMetrics[pageNum] = {
                        mmWidth: mmWidth,
                        mmHeight: mmHeight,
                        pxPerMmX: pxPerMmX,
                        pxPerMmY: pxPerMmY
                    };
                });
                promises.push(pr);
            })(p);
        }
        return Promise.all(promises);
    }

    function renderPage(pageNum) {
        if (!pdfDoc) return;

        pdfDoc.getPage(pageNum).then(function (page) {
            var viewport = page.getViewport({ scale: FIXED_SCALE });

            canvas.height = viewport.height;
            canvas.width = viewport.width;

            overlay.style.width = canvas.width + 'px';
            overlay.style.height = canvas.height + 'px';

            var renderTask = page.render({
                canvasContext: canvasContext,
                viewport: viewport
            });

            renderTask.promise.then(function () {
                renderFieldsForCurrentPage();
                updateStatus('');
            });
        });
    }

    function goToPage(pageNum) {
        if (!pdfDoc) return;
        if (pageNum < 1) pageNum = 1;
        if (pageNum > totalPages) pageNum = totalPages;
        if (pageNum === currentPage) return;

        currentPage = pageNum;
        updatePageLabel();
        renderPage(currentPage);
    }

    // --------------------------------------------------------------------
    // Modalità EDIT: ricostruzione campi da JSON esistente
    // --------------------------------------------------------------------
    function restoreFromExistingMapping() {
        if (!existingMappingArray || !Array.isArray(existingMappingArray)) {
            return;
        }

        for (var i = 0; i < existingMappingArray.length; i++) {
            var item = existingMappingArray[i];
            if (!item) continue;

            var page = item.pagina || 1;
            var coords = item.coordinate_mm || {};

            var xMm = coords.x || 0;
            var yMm = coords.y || 0;
            var widthMm = coords.width || 10;
            var heightMm = coords.height || 5;

            var metrics = pageMetrics[page];
            if (!metrics) continue;

            var fullPlaceholder = item.placeholder_name || '';
            var basePlaceholder = fullPlaceholder;
            var suffixIndex = null;

            var match = fullPlaceholder.match(/^(.*)_idx_(\d+)$/);
            if (match) {
                basePlaceholder = match[1];
                suffixIndex = parseInt(match[2], 10);
            }

            var tpl = getTemplateByPlaceholder(basePlaceholder);
            var typeId = tpl && typeof tpl.type_id !== 'undefined' ? tpl.type_id : (item.type_id || 1);

            var instance = {
                id: generateInstanceId(),
                basePlaceholder: basePlaceholder,
                placeholder_name: fullPlaceholder,
                type_id: typeId,
                page: page,
                xPx: xMm * metrics.pxPerMmX,
                yPx: yMm * metrics.pxPerMmY,
                widthPx: widthMm * metrics.pxPerMmX,
                heightPx: heightMm * metrics.pxPerMmY
            };

            ensurePageArray(page);
            pageFields[page].push(instance);
            fieldInstancesById[instance.id] = instance;

            if (suffixIndex !== null) {
                var current = placeholderCounters[basePlaceholder] || 0;
                if (suffixIndex > current) {
                    placeholderCounters[basePlaceholder] = suffixIndex;
                }
            }
        }
    }

    // --------------------------------------------------------------------
    // Generazione JSON e submit form
    // --------------------------------------------------------------------
    function buildMappingJson() {
        var result = [];

        for (var pageKey in pageFields) {
            if (!pageFields.hasOwnProperty(pageKey)) continue;
            var pageNum = parseInt(pageKey, 10);
            var fields = pageFields[pageKey];
            var metrics = pageMetrics[pageNum];
            if (!metrics) continue;

            for (var i = 0; i < fields.length; i++) {
                var inst = fields[i];

                var xMm = inst.xPx / metrics.pxPerMmX;
                var yMm = inst.yPx / metrics.pxPerMmY;
                var widthMm = inst.widthPx / metrics.pxPerMmX;
                var heightMm = inst.heightPx / metrics.pxPerMmY;

                var item = {
                    placeholder_name: inst.placeholder_name,
                    pagina: pageNum,
                    coordinate_mm: {
                        x: roundMm(xMm),
                        y: roundMm(yMm),
                        width: roundMm(widthMm),
                        height: roundMm(heightMm)
                    },
                    unita: 'mm'
                };

                result.push(item);
            }
        }

        return JSON.stringify(result);
    }

    function handleSaveClick() {
        var json = buildMappingJson();
        var hiddenInput = document.getElementById('mapping_json');
        hiddenInput.value = json;

        if (!json || json === '[]') {
            var proceed = confirm('Nessun campo posizionato. Vuoi salvare comunque?');
            if (!proceed) {
                return;
            }
        }

        formEl.submit();
    }

    // --------------------------------------------------------------------
    // Inizializzazione Interact.js per drag & resize
    // --------------------------------------------------------------------
    function setupInteract() {
        if (typeof interact === 'undefined') {
            console.error('interact.js non caricato.');
            return;
        }

        interact('.pdf-field')
            .draggable({
                listeners: {
                    move: function (event) {
                        var target = event.target;
                        var x = (parseFloat(target.getAttribute('data-x')) || 0) + event.dx;
                        var y = (parseFloat(target.getAttribute('data-y')) || 0) + event.dy;

                        target.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
                        target.setAttribute('data-x', x);
                        target.setAttribute('data-y', y);

                        syncInstanceFromElement(target);
                    }
                },
                modifiers: [
                    interact.modifiers.restrictRect({
                        restriction: 'parent',
                        endOnly: true
                    })
                ],
                inertia: false
            })
            .resizable({
                edges: { left: true, right: true, bottom: true, top: true },
                listeners: {
                    move: function (event) {
                        var target = event.target;

                        var x = parseFloat(target.getAttribute('data-x')) || 0;
                        var y = parseFloat(target.getAttribute('data-y')) || 0;

                        // Aggiorniamo dimensioni
                        target.style.width = event.rect.width + 'px';
                        target.style.height = event.rect.height + 'px';

                        // Se si ridimensiona da sinistra/alto, aggiorna anche la traslazione
                        x += event.deltaRect.left;
                        y += event.deltaRect.top;

                        target.style.transform = 'translate(' + x + 'px, ' + y + 'px)';

                        target.setAttribute('data-x', x);
                        target.setAttribute('data-y', y);

                        syncInstanceFromElement(target);
                    }
                },
                modifiers: [
                    interact.modifiers.restrictRect({
                        restriction: 'parent',
                        endOnly: true
                    }),
                    // consentiamo dimensioni molto piccole senza rimbalzi a una larghezza di default
                    interact.modifiers.restrictSize({
                        min: { width: 2, height: 2 }
                    })
                ],
                inertia: false
            });
    }

    // --------------------------------------------------------------------
    // Bootstrap dell'app
    // --------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        renderFieldTemplates();
        setupInteract();

        btnPrev.addEventListener('click', function () {
            goToPage(currentPage - 1);
        });

        btnNext.addEventListener('click', function () {
            goToPage(currentPage + 1);
        });

        btnSave.addEventListener('click', handleSaveClick);

        if (!PDF_URL) {
            updateStatus('Nessun PDF definito.');
            return;
        }

        updateStatus('Caricamento PDF in corso...');

        pdfjsLib.getDocument(PDF_URL).promise.then(function (doc) {
            pdfDoc = doc;
            totalPages = pdfDoc.numPages;
            currentPage = 1;
            updatePageLabel();

            // Prima calcoliamo le metriche per tutte le pagine
            computeAllPageMetrics().then(function () {
                // Se esiste un mapping pregresso, ricostruisci tutti i campi
                restoreFromExistingMapping();

                // Renderizza la prima pagina
                renderPage(currentPage);
                updateStatus('');
            });
        }).catch(function (error) {
            console.error('Errore caricamento PDF:', error);
            updateStatus('Errore nel caricamento del PDF.');
        });
    });
</script>

</body>
</html>

