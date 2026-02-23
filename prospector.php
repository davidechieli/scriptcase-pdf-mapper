// ============================================================
// Endpoint CQS: apertura file Excel da tabella calcolatori
// calcolatori.file_name = nome descrittivo (senza estensione)
// calcolatori.content   = nome file reale (es: "calcolo rinnovo cqs.xlsx")
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    $conn   = $this->Db;
    $action = $_GET['action'];

    // Mappa action → id calcolatori
    $map = [
        'cqs_debito_residuo' => 3,
        'cqs_v_quinto'       => 1,
        'cqs_rinnovo'        => 2
    ];

    // Se non è un’action CQS, NON bloccare la pagina: lascia proseguire gli altri endpoint
    if (!isset($map[$action])) {
        // do nothing
    } else {
        $calc_id = (int)$map[$action];

        $sql  = "SELECT file_name, content, LENGTH(content) AS content_size
                 FROM calcolatori
                 WHERE id = ?";
        $stmt = $conn->Prepare($sql);
        $rs   = $conn->Execute($stmt, [$calc_id]);

        if (!$rs) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "ERRORE QUERY calcolatori: " . $conn->ErrorMsg();
            exit;
        }

        if ($rs->EOF) {
            header('Content-Type: text/plain; charset=utf-8');
            echo "Calcolatore non trovato: id=$calc_id";
            exit;
        }

        $display_name = trim((string)$rs->fields['file_name']); // es: "Calcolo rinnovo cqs"
        $file_ref     = trim((string)$rs->fields['content']);   // es: "calcolo rinnovo cqs.xlsx"

        if ($file_ref === '') {
            header('Content-Type: text/plain; charset=utf-8');
            echo "File non valorizzato in calcolatori.content (id=$calc_id)";
            exit;
        }

        // Directory candidate (aggiungine altre se necessario)
        $dirs = [
            '/opt/Scriptcase/v9-php81/wwwroot/scriptcase/file/doc/templates/',
            '/opt/Scriptcase/v9-php81/wwwroot/scriptcase/file/doc/',
            // spesso i file progetto stanno qui (se li avete salvati dentro _lib/file)
            (defined('APPPATH') ? APPPATH . '_lib/file/' : null),
            (defined('APPPATH') ? APPPATH . 'files/' : null),
        ];

        // pulizia null
        $dirs = array_values(array_filter($dirs, function($d){ return !empty($d); }));

        // Prova a trovare il file in una delle directory
        $found_path = null;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) continue;
            $candidate = $dir . $file_ref;
            if (file_exists($candidate)) {
                $found_path = $candidate;
                break;
            }
        }

        if ($found_path === null) {
            // Debug: mostra dove ha cercato
            header('Content-Type: text/plain; charset=utf-8');
            echo "FILE NON TROVATO\n";
            echo "action: $action\n";
            echo "calcolatori.id: $calc_id\n";
            echo "display_name: $display_name\n";
            echo "file_ref (content): $file_ref\n";
            echo "Directory provate:\n";
            foreach ($dirs as $dir) {
                echo "- $dir" . (is_dir($dir) ? "" : " (dir inesistente)") . "\n";
            }
            exit;
        }

        // Serve il file trovato
        $ext = strtolower(pathinfo($file_ref, PATHINFO_EXTENSION));

        if ($ext === 'xlsx') {
            $mime = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        } elseif ($ext === 'xlsm') {
            $mime = 'application/vnd.ms-excel.sheet.macroEnabled.12';
        } else {
            // fallback
            $mime = 'application/octet-stream';
        }

        // Nome download: se file_ref non ha estensione o è strano, ripiego su display_name
        $download_name = basename($file_ref);
        if ($download_name === '' || strpos($download_name, '.') === false) {
            $download_name = ($display_name !== '' ? $display_name : 'calcolatore') . '.' . ($ext ?: 'xlsx');
        }

        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="' . $download_name . '"');
        //header('Content-Disposition: inline');
		header('Content-Length: ' . filesize($found_path));
        header('Cache-Control: public, must-revalidate, max-age=0');

        readfile($found_path);
        exit;
    }
}



function genera_password($length = 12) {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $password = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

// Endpoint JSON per caricare un prospect salvato
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && $_GET['action'] === 'load_prospect'
    && isset($_GET['id'])
) {
    $conn = $this->Db;
    $id   = (int) $_GET['id'];

    $sql = "SELECT
                id,
                description,
                pratica_id,
                property_price,
                mortgage_amount,
                duration_years,
                annual_rate,
                family_members,
                borrowers_count,
                total_net_monthly,
                total_net_monthly_gross,
                total_maintenance,
                total_commitments,
                mortgage_payment,
                ltv,
                lti,
                rr,
                rr_indeb_ratio,
                rr_mant_indeb_ratio,
                chebanca_rr,
                subsistence_income,
                borrowers_json
            FROM prospects
            WHERE id = ?";
    $stmt = $conn->Prepare($sql);
    $rs   = $conn->Execute($stmt, [$id]);

    $out = ['success' => false, 'error' => 'Prospect non trovato'];
    if ($rs && !$rs->EOF) {
        $row = $rs->fields;

        $borrowers = [];
        if (!empty($row['borrowers_json'])) {
            $borrowers = json_decode($row['borrowers_json'], true);
            if (!is_array($borrowers)) {
                $borrowers = [];
            }
        }

        $out = [
            'success'  => true,
            'prospect' => [
                'id'                      => $row['id'],
                'description'             => $row['description'],
                'pratica_id'              => $row['pratica_id'],
                'property_price'          => (float)$row['property_price'],
                'mortgage_amount'         => (float)$row['mortgage_amount'],
                'duration_years'          => (int)$row['duration_years'],
                'annual_rate'             => (float)$row['annual_rate'],
                'family_members'          => (int)$row['family_members'],
                'borrowers_count'         => (int)$row['borrowers_count'],
                'total_net_monthly'       => (float)$row['total_net_monthly'],
                'total_net_monthly_gross' => (float)$row['total_net_monthly_gross'],
                'total_maintenance'       => (float)$row['total_maintenance'],
                'total_commitments'       => (float)$row['total_commitments'],
                'mortgage_payment'        => (float)$row['mortgage_payment'],
                'ltv'                     => (float)$row['ltv'],
                'lti'                     => (float)$row['lti'],
                'rr'                      => (float)$row['rr'],
                'rr_indeb_ratio'          => (float)$row['rr_indeb_ratio'],
                'rr_mant_indeb_ratio'     => (float)$row['rr_mant_indeb_ratio'],
                'chebanca_rr'             => (float)$row['chebanca_rr'],
                'subsistence_income'      => (float)$row['subsistence_income'],
                'borrowers_data'          => $borrowers
            ]
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($out);
    exit;
}

// Verifica ATC: notifica di gruppo a Operatori e Admin
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_GET['action'])
    && $_GET['action'] === 'cqs_verifica_atc') {

    $input = file_get_contents('php://input');
    $data  = is_string($input) ? json_decode($input, true) : null;
    $cf_piva = trim((string)($data['cf_piva'] ?? $data['piva'] ?? ''));

    if ($cf_piva === '') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Inserire Codice Fiscale o P.IVA']);
        exit;
    }

    $prospectId = isset($_GET['prospect_id']) ? (int)$_GET['prospect_id'] : (isset($data['prospect_id']) ? (int)$data['prospect_id'] : null);
    $title = $prospectId
        ? "Nuova verifica ATC richiesta per prospect {$prospectId}"
        : "Nuova verifica ATC richiesta";

    $usrLogin = isset($_SESSION['usr_login']) ? $_SESSION['usr_login'] : 'sistema';
    $operatorSubject = $title;
    $operatorText = "È stata richiesta una verifica ATC per CF/P.IVA: " . $cf_piva . ". Controlla la sezione Pratiche nel tuo account utente.";

    $htmlOperatorContent = "
<html>
<head>
	<style>
		body { font-family: Arial, sans-serif; color: #333; background-color: #f4f4f4; padding: 20px; }
		.container { max-width: 600px; margin: 0 auto; background-color: #fff; border-radius: 8px; padding: 30px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1); }
		.header { font-size: 20px; font-weight: bold; color: #004080; margin-bottom: 20px; text-align: center; }
		.footer { font-size: 12px; color: #777; margin-top: 40px; text-align: center; border-top: 1px solid #e0e0e0; padding-top: 20px; }
	</style>
</head>
<body>
	<div class='container'>
		<div class='header'>" . htmlspecialchars($operatorSubject) . "</div>
		<p>" . htmlspecialchars($operatorText) . "</p>
		<div class='footer'>
			Euroansa<br>Via G. F. Kennedy, 7 - Frosinone (FR) 03100 - Italy<br>Tel. 077/51438228<br>Email: segreteria.centrocqs@euroansa.it<br><br>
			<em>Questa è una comunicazione automatica. Si prega di non rispondere a questa email.</em>
		</div>
	</div>
</body>
</html>";

    sc_send_notification([
        'title'        => $title,
        'message'      => $htmlOperatorContent,
        'destiny_type' => 'group',
        'to'           => 'Operatore;Administrator;Sys Admin',
        'from'         => $usrLogin,
        'link'         => '',
        'dtexpire'     => '',
        'profile'      => 'Notifications_Euroansa'
    ]);

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true]);
    exit;
}

//salvataggio dati
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_SERVER['CONTENT_TYPE'])
    && stripos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {

    $input = file_get_contents('php://input');
    $data  = json_decode($input, true);

    if (!is_array($data)) { 
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'Dati non validi']);
        exit; 
    }

    $conn = $this->Db;
    $response = ['success' => true, 'intestatari_processed' => 0, 'prospect_saved' => false];
    $description = '';

    // SEMPRE gestisce intestatari (anche se non presenti)
    if (isset($data['intestatari']) && is_array($data['intestatari'])) {
        $nomi = [];
        foreach ($data['intestatari'] as $persona) {
            $email      = trim($persona['email']      ?? '');
            $first_name = trim($persona['first_name'] ?? '');
            $last_name  = trim($persona['last_name']  ?? '');

            // stringa completa per visualizzazione / sec_users
            $nome_completo = trim($first_name . ' ' . $last_name);

            if ($nome_completo !== '') {
                $nomi[] = $nome_completo;
            }

            if ($email !== '' && $nome_completo !== '') {
                $email_escaped = $conn->qstr($email);

                sc_lookup(check_user, "SELECT COUNT(*) FROM sec_users WHERE email = {$email_escaped}");
                $exists = isset({check_user[0][0]}) ? {check_user[0][0]} : 0;

                if ($exists > 0) {
                    $sql_upd = "UPDATE sec_users SET name = ? WHERE email = ?";
                    $stmt = $conn->Prepare($sql_upd);
                    $conn->Execute($stmt, [$nome_completo, $email]);
                } else {
                    $password    = genera_password(12);
                    $hashed_pass = sha1($password);
                    $sql_ins = "INSERT INTO sec_users (login, name, pswd, email, active)
                                VALUES (?, ?, ?, ?, ?)";
                    $stmt = $conn->Prepare($sql_ins);
                    $conn->Execute($stmt, [$email, $nome_completo, $hashed_pass, $email, "Y"]);
                }

                sc_lookup(check_group,
                    "SELECT COUNT(*) FROM sec_users_groups WHERE login = {$email_escaped} AND group_id = 5",
                    "euroansa"
                );
                $group_exists = isset({check_group[0][0]}) ? {check_group[0][0]} : 0;
                if ($group_exists == 0) {
                    $sql_grp = "INSERT INTO sec_users_groups (login, group_id) VALUES (?, ?)";
                    $stmt = $conn->Prepare($sql_grp);
                    $conn->Execute($stmt, [$email, 5]);
                }
                $response['intestatari_processed']++;
            }
        }
        $description = implode(', ', $nomi);
    }

    // Gestione salvataggio prospect
    if (isset($data['prospect']) && is_array($data['prospect'])) {
        $prospect = $data['prospect'];

        // borrowers_data già contiene first_name/last_name dal JS
        $borrowers_data = $prospect['borrowers_data'] ?? [];
        // i record CQS verranno aggiunti lato JS con tipo: 'cqs' e cf_piva
        $borrowers_json = json_encode($borrowers_data, JSON_UNESCAPED_UNICODE);

        $sql = "INSERT INTO prospects (
            description, created_by, pratica_id, property_price, mortgage_amount, duration_years, 
            annual_rate, family_members, borrowers_count, total_net_monthly, 
            total_net_monthly_gross, total_maintenance, total_commitments, 
            mortgage_payment, ltv, lti, rr, rr_indeb_ratio, rr_mant_indeb_ratio, 
            chebanca_rr, subsistence_income, borrowers_json
        ) VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
        )";

        $stmt = $conn->Prepare($sql);
        $params = [
            "Prospect per " . ($prospect['description'] ?? $description) . " del " . date('Y-m-d H:i:s'),
            $_SESSION['usr_login'] ?? 'unknown',
            $prospect['pratica_id'] ?? null,
            $prospect['property_price'],
            $prospect['mortgage_amount'],
            $prospect['duration_years'],
            $prospect['annual_rate'],
            $prospect['family_members'],
            $prospect['borrowers_count'],
            $prospect['total_net_monthly'],
            $prospect['total_net_monthly_gross'],
            $prospect['total_maintenance'],
            $prospect['total_commitments'],
            $prospect['mortgage_payment'],
            $prospect['ltv'],
            $prospect['lti'],
            $prospect['rr'],
            $prospect['rr_indeb_ratio'],
            $prospect['rr_mant_indeb_ratio'],
            $prospect['chebanca_rr'],
            $prospect['subsistence_income'],
            $borrowers_json
        ];

        $result = $conn->Execute($stmt, $params);
        if ($result) {
            $response['prospect_saved'] = true;
            $response['prospect_id'] = $conn->Insert_ID();
        } else {
            $response['success'] = false;
            $response['error'] = 'Errore DB: ' . $conn->ErrorMsg();
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

// Endpoint JSON per istituti_sussistenze + istituti_credito
if ($_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['action'])
    && $_GET['action'] === 'load_sussistenze') {

    $conn = $this->Db;

    $sql = "SELECT s.id,
                   s.istituto_id,
                   i.ragione_sociale AS nome_banca,
                   s.variant,
                   s.soglia_min,
                   s.valore_rr_min,
                   s.soglia_max,
                   s.valore_rr_mid,
                   s.valore_rr_max,
                   s.rr_max,
                   s.sussistenza_1,
                   s.sussistenza_2,
                   s.sussistenza_3,
                   s.sussistenza_4,
                   s.sussistenza_5,
                   s.sussistenza_6,
                   s.sussistenza_7
            FROM istituti_sussistenze s
            JOIN istituti_credito i ON i.id = s.istituto_id
            ORDER BY i.ragione_sociale, s.id";

    $rs = $conn->Execute($sql);
    $rows = [];
    if ($rs) {
        while (!$rs->EOF) {
            $rows[] = $rs->fields;
            $rs->MoveNext();
        }
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($rows);
    exit;
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Prospector - Mutui </title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0; padding: 10px; background: #f0f0f0; font-size: 12px; line-height: 1.3;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 6px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: #727cf5;
            color: white;
            padding: 15px 20px;
            text-align: center;
            font-weight: 600;
            font-size: 18px;
        }
        .tab-bar {
            background:#e9ecef;
            padding:6px 15px;
            display:flex;
            gap:8px;
        }
        .content { padding: 15px; }
        h3 {
            margin: 10px 0 4px 0;
            color: #208090;
            font-weight: 600;
            font-size: 14px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
            margin-bottom: 15px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 5px 6px;
            text-align: left;
            vertical-align: middle;
        }
        th {
            background: #e3f0f1;
            font-weight: 600;
            white-space: nowrap;
        }
        tr:nth-child(even) { background: #fafafa; }
        input, select {
            width: 100%;
            box-sizing: border-box;
            font-size: 11px;
            padding: 4px 6px;
            border: 1px solid #ccc;
            border-radius: 3px;
        }
        input:focus, select:focus {
            outline: none;
            border-color: #208090;
            box-shadow: 0 0 5px rgba(32,128,144,0.3);
        }
        .cqs-form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 10px 20px;
            margin-bottom: 15px;
        }
        .cqs-form-grid label { display: block; margin-bottom: 2px; font-weight: 500; color: #333; }
        .cqs-form-grid input, .cqs-form-grid select { max-width: 100%; }
        .btn-container {
            text-align: center;
            padding: 12px;
            background: #f9f9f9;
            border-top: 1px solid #ddd;
        }
        button {
            background: #727cf5;
            color: white;
            padding: 8px 20px;
            border: none;
            border-radius: 4px;
            font-weight: 600;
            font-size: 13px;
            cursor: pointer;
            margin: 0 4px;
        }
        button.icon{
             background: none;
        }
        button:hover { background: #535ad4; }
        button.secondary {
            background: #6c757d;
        }
        button.secondary:hover { background: #565e63; }
        button:disabled {
            background: #bfc5d1;
            color: #ffffff;
            cursor: not-allowed;
            opacity: 0.75;
            box-shadow: none;
        }
        button.save-primary {
            background: #28a745;
        }
        button.save-primary:disabled {
            background: #a3d5b2;
            color: #f5f5f5;
        }
        .result-value {
            font-weight: 700;
            font-size: 13px;
            color: #333;
            text-align: right;
            padding-right: 8px;
            white-space: nowrap;
        }
        .age-warning { background: #f8d7da; color: #721c24; font-weight: 600; }
        .red-cell { background: #f8d7da !important; color: #b71c1c !important; font-weight: 700;}
        .not-feasible-row { background: #fbeaea !important; }
        #results { display: none; }
        #results.show { display: block; }
    </style>
    <script>
        function showTab(tab) {
            const mutuo = document.getElementById('tab_mutuo');
            const cqs   = document.getElementById('tab_cqs');
            const btnM  = document.getElementById('tab_btn_mutuo');
            const btnC  = document.getElementById('tab_btn_cqs');

            if (tab === 'cqs') {
                if (mutuo) mutuo.style.display = 'none';
                if (cqs)   cqs.style.display   = 'block';
                if (btnM)  btnM.classList.remove('save-primary');
                if (btnC)  btnC.classList.add('save-primary');
            } else {
                if (mutuo) mutuo.style.display = 'block';
                if (cqs)   cqs.style.display   = 'none';
                if (btnM)  btnM.classList.add('save-primary');
                if (btnC)  btnC.classList.remove('save-primary');
            }
        }
        function openCqsExcel(action) {
            window.open(window.location.pathname + '?action=' + encodeURIComponent(action), '_blank');
        }
        function sendCqsVerificaAtc() {
            const cfPiva = (document.getElementById('atc_cf_piva')?.value || '').trim();
            if (!cfPiva) {
                alert('Inserire Codice Fiscale o P.IVA');
                return;
            }
            const loadId = document.getElementById('load_prospect_id')?.value;
            const payload = { cf_piva: cfPiva };
            if (loadId) payload.prospect_id = parseInt(loadId, 10);
            fetch(window.location.pathname + '?action=cqs_verifica_atc', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Richiesta ATC inviata al backoffice.');
                    } else {
                        alert('Errore verifica ATC: ' + (data.error || 'errore sconosciuto'));
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Errore di comunicazione per la verifica ATC');
                });
        }
    </script>
</head>
<body>
<div class="container">
    <div class="header"> Prospector - Mutui/Prestiti</div>
    <div class="tab-bar">
        <button type="button" id="tab_btn_mutuo" class="secondary save-primary" onclick="showTab('mutuo')">
            Mutuo / Prestiti
        </button>
        <button type="button" id="tab_btn_cqs" class="secondary" onclick="showTab('cqs')">
            Cessione del Quinto
        </button>
    </div>

    <!-- TAB MUTUO / PRESTITI: codice originale -->
    <div class="content" id="tab_mutuo" style="display:block;">
        <?php /* INIZIO BLOCCO ORIGINALE MUTUO/PRESTITI */ ?>
        <h3>Intestatari / Garanti</h3>
        <table class="intestatari-table" id="intestatari-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Nome</th>
                    <th>Cognome</th>
                    <th>Email</th>
                    <th>Anno Nasc.</th>
                    <th>Età</th>
                    <th>Età Fine Mutuo</th>
                    <th>Durata Max 2/3</th>
                    <th>Tipo</th>
                    <th>Giorni CUD</th>
                    <th>Reddito Annuo (€)</th>
                    <th>Bonus (€)</th>
                    <th>IRPEF (€)</th>
                    <th>Addiz. (€)</th>
                    <th>Mant. (€)</th>
                    <th>Impegni Mens. (€)</th>
                </tr>
            </thead>
            <tbody id="intestatari-tbody"></tbody>
        </table>

        <h3>Dati Mutuo e Immobile</h3>
        <table>
            <tbody>
                <tr>
                    <th>Prezzo Immobile (€)</th>
                    <td><input type="number" required id="property_price" value="225000" step="1000" /></td>
                    <th>Importo Mutuo (€)</th>
                    <td><input type="number" required id="mortgage_amount" value="150000" step="1000" /></td>
                </tr>
                <tr>
                    <th>Durata (anni)</th>
                    <td><input type="number" required id="duration_years" value="30" min="5" max="40" /></td>
                    <th>Tasso Annuo (%)</th>
                    <td><input type="number" required id="annual_rate" value="5" step="0.01" /></td>
                </tr>
                <tr>
                    <th>N° componenti nucleo</th>
                    <td><input type="number" required id="family_members" value="1" min="1" max="7" /></td>
                    <th></th>
                    <td></td>
                </tr>
            </tbody>
        </table>

        <div class="btn-container">
            <div style="margin-bottom:8px;">
                <label for="load_prospect_id" style="margin-right:6px;">ID Prospect:</label>
                <input type="number" id="load_prospect_id" style="width:90px;" />
                <button type="button" class="secondary" onclick="loadProspect()">CARICA PROSPECT</button>
            </div>

            <button id="btn_calcola" onclick="saveAndCalculate()" disabled> CALCOLA</button>
            <button class="secondary" onclick="resetForm()"> RESET</button>
            <button id="btn_save_prospect" onclick="saveProspect()" disabled class="save-primary"> SALVA PROSPECT</button>
        </div>

        <div id="results">
            <h3>Riepilogo Intestatari</h3>
            <table>
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Età</th>
                        <th>Età Fine Mutuo</th>
                        <th>Reddito Netto Mensile</th>
                        <th>Mantenimento</th>
                        <th>Impegni</th>
                    </tr>
                </thead>
                <tbody id="results-tbody"></tbody>
            </table>

            <h3> Parametri</h3>
            <table>
                <tbody>
                    <tr><th>Reddito Netto Mensile</th><td id="family_income" class="result-value"></td></tr>
                    <tr><th>Totale Mantenimento</th><td id="family_maintenance" class="result-value"></td></tr>
                    <tr><th>Totale Impegni Mensili</th><td id="family_commitments" class="result-value"></td></tr>
                    <tr><th>Reddito di Sussistenza</th><td id="subsistence_income" class="result-value"></td></tr>
                </tbody>
            </table>

            <h3> Parametri RR e Rapporti</h3>
            <table>
                <tbody>
                    <tr><th>Rata Mutuo Calcolata</th><td id="mortgage_rate" class="result-value"></td></tr>
                    <tr><th>LTV</th><td id="ltv_value" class="result-value"></td></tr>
                    <tr><th>LTI</th><td id="lti_value" class="result-value"></td></tr>
                    <tr><th>RR (Rata / Reddito)</th><td id="rr_value" class="result-value"></td></tr>
                    <tr><th>Rapporto RR / Indebit.</th><td id="rr_indeb_ratio" class="result-value"></td></tr>
                    <tr><th>Rapporto RR - Mant / Indeb.</th><td id="rr_mant_indeb_ratio" class="result-value"></td></tr>
                    <tr><th>CheBanca RR</th><td id="chebanca_rr" class="result-value"></td></tr>
                </tbody>
            </table>
            <h3> Perizia (valori indicativi)</h3>
            <table>
                <tbody>
                    <tr><th>Valore Perizia 80%</th><td id="perizia_80" class="result-value"></td></tr>
                    <tr><th>Valore Perizia 70%</th><td id="perizia_70" class="result-value"></td></tr>
                    <tr><th>Valore Ipotesi 95%</th><td id="perizia_95" class="result-value"></td></tr>
                </tbody>
            </table>

            <h3> Valutazione Mutuo per Istituto</h3>
            <table id="valuation-table">
                <thead>
                    <tr>
                        <th rowspan="2">Banca / Variante</th>
                        <th colspan="2">Valutazione</th>
                        <th colspan="2">Margini</th>
                        <th colspan="2">Massimi</th>
                        <th rowspan="2">Esito</th>
                    </tr>
                    <tr>
                        <th>R/R</th>
                        <th>Sussistenza</th>
                        <th>R/R</th>
                        <th>Sussistenza</th>
                        <th>Rata Max</th>
                        <th>Mutuo Max</th>
                    </tr>
                </thead>
                <tbody id="valuation-tbody"></tbody>
            </table>
        </div>

        <!-- Popup calcolo reddito netto (ING) -->
        <div id="netIncomeModal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9999; align-items:center; justify-content:center;">
            <div style="background:#fff; border-radius:6px; padding:15px 18px; width:420px; max-width:95%; box-shadow:0 4px 16px rgba(0,0,0,0.25); font-size:12px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <strong>Calcolo reddito netto (ING)</strong>
                    <button type="button" onclick="closeNetIncomeCalc()" style="border:none;background:none;font-size:16px;cursor:pointer;color:gray;">✖</button>
                </div>

                <div style="margin-bottom:8px; font-size:11px; color:#555;">
                    Calcolo di supporto, non viene salvato nei dati dell’intestatario.
                </div>

                <table style="width:100%; border-collapse:collapse; font-size:11px; margin-bottom:8px;">
                    <tr>
                        <th style="text-align:left; padding:3px 2px;">Paga base mensile</th>
                        <td style="padding:3px 2px;"><input type="number" id="ni_base_pay" step="10" /></td>
                    </tr>
                    <tr>
                        <th style="text-align:left; padding:3px 2px;">Mensilità</th>
                        <td style="padding:3px 2px;"><input type="number" id="ni_months" value="12" min="12" max="14" /></td>
                    </tr>
                    <tr>
                        <th style="text-align:left; padding:3px 2px;">Tipologia lavoro</th>
                        <td style="padding:3px 2px;">
                            <select id="ni_job_type">
                                <option value="privato">Dipendente privato</option>
                                <option value="pubblico">Dipendente pubblico</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <button type="button" onclick="runNetIncomeCalc()" style="margin-bottom:8px;">Calcola</button>

                <table style="width:100%; border-collapse:collapse; font-size:11px;">
                    <tr>
                        <th style="text-align:left; padding:3px 2px;">Retribuzione annua lorda</th>
                        <td style="padding:3px 2px;" id="ni_ral_out"></td>
                    </tr>
                    <tr>
                        <th style="text-align:left; padding:3px 2px;">Reddito netto mensile</th>
                        <td style="padding:3px 2px;" id="ni_net_month_out"></td>
                    </tr>
                    <tr>
                        <th style="text-align:left; padding:3px 2px;">Reddito annuo mensile</th>
                        <td style="padding:3px 2px;" id="ni_net_year_out"></td>
                    </tr>
                </table>
            </div>
        </div>

        <?php /* FINE BLOCCO ORIGINALE MUTUO/PRESTITI */ ?>
    </div>

    <!-- TAB CQS: un solo beneficiario -->
    <div class="content" id="tab_cqs" style="display:none;">
        <h3>Beneficiario CQS</h3>
        <div id="cqs-beneficiary-form" class="cqs-form-grid">
            <div><label for="cqs_first_name_1">Nome</label><input type="text" id="cqs_first_name_1" placeholder="Nome" /></div>
            <div><label for="cqs_last_name_1">Cognome</label><input type="text" id="cqs_last_name_1" placeholder="Cognome" /></div>
            <div><label for="cqs_email_1">Email</label><input type="email" id="cqs_email_1" placeholder="email@email.it" /></div>
            <div><label for="cqs_cf_piva_1">CF / P.IVA</label><input type="text" id="cqs_cf_piva_1" placeholder="CF o P.IVA" /></div>
            <div><label for="cqs_birth_year_1">Anno Nasc.</label><input type="number" id="cqs_birth_year_1" min="1940" max="2010" /></div>
            <div><label for="cqs_type_1">Tipo</label>
                <select id="cqs_type_1">
                    <option value="">—</option>
                    <option value="intestatario">Intestatario</option>
                    <option value="garante">Garante</option>
                </select>
            </div>
            <div><label for="cqs_cud_days_1">Giorni CUD</label><input type="number" id="cqs_cud_days_1" value="365" min="1" max="365" style="width:70px;" /></div>
            <div><label for="cqs_annual_income_1">Reddito Annuo (€)</label><input type="number" id="cqs_annual_income_1" step="100" /></div>
            <div><label for="cqs_bonus_1">Bonus (€)</label><input type="number" id="cqs_bonus_1" value="0" /></div>
            <div><label for="cqs_irpef_1">IRPEF (€)</label><input type="number" id="cqs_irpef_1" value="0" /></div>
            <div><label for="cqs_additions_1">Addiz. (€)</label><input type="number" id="cqs_additions_1" value="0" /></div>
            <div><label for="cqs_maintenance_1">Mant. (€)</label><input type="number" id="cqs_maintenance_1" value="0" /></div>
            <div><label for="cqs_commitments_1">Impegni Mens. (€)</label><input type="number" id="cqs_commitments_1" value="0" /></div>
            <div><label for="cqs_importo_richiesto">Importo richiesto (€)</label><input type="number" id="cqs_importo_richiesto" step="100" min="0" /></div>
            <div><label for="cqs_durata_mesi">Durata (mesi)</label><input type="number" id="cqs_durata_mesi" min="1" max="120" value="60" /></div>
        </div>

        <h3>Azioni CQS</h3>
        <div class="btn-container">
            <button type="button" class="secondary" onclick="openCqsExcel('cqs_debito_residuo')">
                CALCOLO DEBITO RESIDUO
            </button>
            <button type="button" class="secondary" onclick="openCqsExcel('cqs_v_quinto')">
                CALCOLO V° DEL DIPENDENTE
            </button>
            <button type="button" class="secondary" onclick="openCqsExcel('cqs_rinnovo')">
                CALCOLO RINNOVO CQS
            </button>
			<button type="button" class="secondary" onclick="resetCqsForm()">
       			 RESET CQS
   			</button>
        </div>

        <h3>Verifica ATC</h3>
        <div style="margin-top:8px; display:flex; gap:8px; align-items:center;">
            <label for="atc_cf_piva" style="white-space:nowrap;">CF / P.IVA:</label>
            <input type="text" id="atc_cf_piva" style="max-width:200px;" />
            <button type="button" class="secondary" onclick="sendCqsVerificaAtc()">Verifica ATC</button>
        </div>
		<div class="btn-container" style="margin-top:12px;">
			<button type="button" class="save-primary" onclick="saveFromCqs()">
				SALVA PREVENTIVO (CQS + MUTUO)
			</button>
</div>
    </div>
</div>

<script>
// === JS originale mutuo/prestiti + estensioni CQS ===
let SUSSISTENZE_DATA = [];
let prospectData = {};
const INTESTATARI_COUNT = 4;
let CURRENT_PERSON_INDEX = null;

function validateBorrowers() {
    let ok = false;

    for (let i = 1; i <= INTESTATARI_COUNT; i++) {
        const first  = document.getElementById(`first_name_${i}`)?.value.trim() || '';
        const last   = document.getElementById(`last_name_${i}`)?.value.trim() || '';
        const email  = document.getElementById(`email_${i}`)?.value.trim() || '';
        const birth  = document.getElementById(`birth_year_${i}`)?.value.trim() || '';
        const type   = document.getElementById(`type_${i}`)?.value || '';
        const incomeVal = document.getElementById(`annual_income_${i}`)?.value;
        const income = incomeVal !== '' && incomeVal !== null ? parseFloat(incomeVal) : 0;

        const rowComplete =
            (first !== '' || last !== '') &&
            email !== '' &&
            birth !== '' &&
            type !== '' &&
            income > 0;

        if (rowComplete) {
            ok = true;
            break;
        }
    }

    const btnCalc  = document.getElementById('btn_calcola');
    const btnSave  = document.getElementById('btn_save_prospect');

    if (btnCalc) btnCalc.disabled = !ok;
    if (btnSave) btnSave.disabled = !ok;
}

function loadSussistenze() {
    return fetch(window.location.pathname + '?action=load_sussistenze')
        .then(r => r.json())
        .then(data => { SUSSISTENZE_DATA = data || []; })
        .catch(console.error);
}

function saveAndCalculate() {
  const intestatari = [];
  const nomi = [];

  for (let i = 1; i <= INTESTATARI_COUNT; i++) {
    const firstEl = document.getElementById(`first_name_${i}`);
    const lastEl  = document.getElementById(`last_name_${i}`);
    const emailEl = document.getElementById(`email_${i}`);

    const first_name = firstEl ? firstEl.value.trim() : "";
    const last_name  = lastEl  ? lastEl.value.trim()  : "";
    const email      = emailEl ? emailEl.value.trim() : "";

    if (first_name || last_name || email) {
      intestatari.push({ first_name, last_name, email });
      const full = (first_name + ' ' + last_name).trim();
      if (full) {
        nomi.push(full);
      }
    }
  }

  const description = nomi.join(", ");

  const payload = {
    intestatari,
    description
  };

  fetch(window.location.pathname, {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(payload)
  })
    .then(r => r.json())
    .then(data => {
      console.log("Intestatari salvati", data);
      calculateFeasibility();
    })
    .catch(error => {
      console.error("Errore", error);
      calculateFeasibility();
    });
}

function saveFromCqs() {
  // 1) porta i dati CQS nei campi mutuo (così calculateFeasibility li usa)
  syncCqsToMutuoFields({ overwrite: false });

  // 2) calcoli prima del salvataggio
  calculateFeasibility();

  // 3) salva (salva sia mutuo che CQS come già fai)
  saveProspect();
}

function saveProspect() {
    const nomi = [];
    for (let i = 1; i <= INTESTATARI_COUNT; i++) {
        const first = document.getElementById(`first_name_${i}`).value.trim();
        const last  = document.getElementById(`last_name_${i}`).value.trim();
        const full  = (first + ' ' + last).trim();
        if (full) nomi.push(full);
    }
    const description = nomi.join(', ');

    let familyNetMonthly = 0, familyNetMonthlyBeforeMant = 0, familyCommitments = 0, 
        familyMaintenance = 0, componentCount = 0;
    const borrowersData = [];
    
    for (let i = 1; i <= INTESTATARI_COUNT; i++) {
        const first_name = document.getElementById(`first_name_${i}`).value.trim();
        const last_name  = document.getElementById(`last_name_${i}`).value.trim();
        const email = document.getElementById(`email_${i}`).value.trim();
        const birthYear = parseInt(document.getElementById(`birth_year_${i}`).value) || 0;
        const type = document.getElementById(`type_${i}`).value;
        const annualIncome = parseFloat(document.getElementById(`annual_income_${i}`).value) || 0;
        const bonus = parseFloat(document.getElementById(`bonus_${i}`).value) || 0;
        const irpef = parseFloat(document.getElementById(`irpef_${i}`).value) || 0;
        const additions = parseFloat(document.getElementById(`additions_${i}`).value) || 0;
        const maintenance = parseFloat(document.getElementById(`maintenance_${i}`).value) || 0;
        const commitments = parseFloat(document.getElementById(`commitments_${i}`).value) || 0;
        const cudDays = parseFloat(document.getElementById(`cud_days_${i}`).value) || 365;

        if ((first_name || last_name) && type) {
            const age = calculateAge(birthYear);
            const netAnnualBeforeMant = annualIncome + bonus - irpef - additions;
            const netMonthlyBeforeMant = (netAnnualBeforeMant / 12 * (cudDays / 365));
            const netMonthly = calculateNetMonthlyIncome(annualIncome, bonus, irpef, additions, maintenance, cudDays);

            borrowersData.push({
                first_name,
                last_name,
                email,
                age,
                birthYear,
                type,
                annualIncome,
                bonus,
                irpef,
                additions,
                maintenance,
                commitments,
                netMonthly
            });

            familyNetMonthly += netMonthly;
            familyNetMonthlyBeforeMant += netMonthlyBeforeMant;
            familyCommitments += commitments;
            familyMaintenance += maintenance;
            componentCount++;
        }
    }

    // Se c’è dati CQS: un solo oggetto in borrowers_data (stile mutuo + cf_piva, importo_richiesto, durata_mesi)
    const i = 1;
    const cqsFirst = (document.getElementById(`cqs_first_name_${i}`)?.value || '').trim();
    const cqsLast  = (document.getElementById(`cqs_last_name_${i}`)?.value || '').trim();
    const cqsEmail = (document.getElementById(`cqs_email_${i}`)?.value || '').trim();
    const cf_piva  = (document.getElementById(`cqs_cf_piva_${i}`)?.value || '').trim();
    const hasCqsData = !!(cqsFirst || cqsLast || cqsEmail || cf_piva);

    if (hasCqsData) {
        const birthYear  = parseInt(document.getElementById(`cqs_birth_year_${i}`)?.value) || 0;
        const type       = (document.getElementById(`cqs_type_${i}`)?.value || '').trim();
        const annualIncome = parseFloat(document.getElementById(`cqs_annual_income_${i}`)?.value) || 0;
        const bonus        = parseFloat(document.getElementById(`cqs_bonus_${i}`)?.value) || 0;
        const irpef        = parseFloat(document.getElementById(`cqs_irpef_${i}`)?.value) || 0;
        const additions    = parseFloat(document.getElementById(`cqs_additions_${i}`)?.value) || 0;
        const maintenance  = parseFloat(document.getElementById(`cqs_maintenance_${i}`)?.value) || 0;
        const commitments  = parseFloat(document.getElementById(`cqs_commitments_${i}`)?.value) || 0;
        const cudDays      = parseFloat(document.getElementById(`cqs_cud_days_${i}`)?.value) || 365;
        let importoRichiesto = parseFloat(document.getElementById('cqs_importo_richiesto')?.value) || 0;
        let durataMesi = parseInt(document.getElementById('cqs_durata_mesi')?.value, 10) || 60;
        durataMesi = Math.min(120, Math.max(1, durataMesi));
        const netMonthly = calculateNetMonthlyIncome(annualIncome, bonus, irpef, additions, maintenance, cudDays);
        borrowersData.length = 0;
        borrowersData.push({
            first_name: cqsFirst,
            last_name: cqsLast,
            email: cqsEmail,
            age: calculateAge(birthYear),
            birthYear,
            type,
            annualIncome,
            bonus,
            irpef,
            additions,
            maintenance,
            commitments,
            netMonthly,
            cf_piva,
            importo_richiesto: importoRichiesto,
            durata_mesi: durataMesi
        });
        familyNetMonthly = netMonthly;
        familyNetMonthlyBeforeMant = (annualIncome + bonus - irpef - additions) / 12 * (cudDays / 365);
        familyCommitments = commitments;
        familyMaintenance = maintenance;
        componentCount = 1;
    } else {
        // Solo dati CQS (nessun campo CQS compilato): nessun secondo oggetto; borrowersData resta dai mutuo
    }

    const propertyPrice = parseFloat(document.getElementById('property_price').value) || 0;
    const mortgageAmount = parseFloat(document.getElementById('mortgage_amount').value) || 0;
    const durationYears = parseInt(document.getElementById('duration_years').value) || 1;
    const annualRate = parseFloat(document.getElementById('annual_rate').value) || 0;
    const familyMembers = parseInt(document.getElementById('family_members').value) || 0;
    const mortgagePayment = calculateMortgagePayment(mortgageAmount, annualRate, durationYears);

    const ltv = propertyPrice > 0 ? (mortgageAmount / propertyPrice) * 100 : 0;
    const lti = familyNetMonthlyBeforeMant > 0 ? (mortgageAmount / familyNetMonthlyBeforeMant) * 12 : 0;
    const rr = familyNetMonthly > 0 ? (mortgagePayment / familyNetMonthly) * 100 : 0;
    
    const incomeForRatios = familyNetMonthlyBeforeMant;
    const totalIndeb = mortgagePayment + familyCommitments + familyMaintenance;
    const rrindebratio = incomeForRatios > 0 ? (totalIndeb / incomeForRatios) * 100 : 0;
    
    const denominatorRRMant = incomeForRatios - familyMaintenance;
    const rrmantindebratio = denominatorRRMant > 0 ? 
        ((mortgagePayment + familyCommitments) / denominatorRRMant) * 100 : 0;
    
    const chebancarr = familyNetMonthlyBeforeMant > 0 ?
        (mortgagePayment / (familyNetMonthlyBeforeMant - familyCommitments - familyMaintenance)) * 100 : 0;
    
    const subsistenceincome = familyNetMonthly - familyMaintenance - familyCommitments - mortgagePayment;

    const intestatari = [];
    for (let j = 1; j <= INTESTATARI_COUNT; j++) {
        const first = (document.getElementById(`first_name_${j}`)?.value || '').trim();
        const last  = (document.getElementById(`last_name_${j}`)?.value || '').trim();
        const em    = (document.getElementById(`email_${j}`)?.value || '').trim();
        if (first || last || em) intestatari.push({ first_name: first, last_name: last, email: em });
    }

    const prospectData = {
        intestatari,
        prospect: {
            description: description,
            pratica_id: null,
            property_price: propertyPrice,
            mortgage_amount: mortgageAmount,
            duration_years: durationYears,
            annual_rate: annualRate,
            family_members: familyMembers,
            borrowers_count: componentCount,
            total_net_monthly: familyNetMonthly,
            total_net_monthly_gross: familyNetMonthlyBeforeMant,
            total_maintenance: familyMaintenance,
            total_commitments: familyCommitments,
            mortgage_payment: mortgagePayment,
            ltv: ltv,
            lti: lti,
            rr: rr,
            rr_indeb_ratio: rrindebratio,
            rr_mant_indeb_ratio: rrmantindebratio,
            chebanca_rr: chebancarr,
            subsistence_income: subsistenceincome,
            borrowers_data: borrowersData
        }
    };

    fetch(window.location.pathname, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(prospectData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Prospect salvato.');
        } else {
            alert('Errore salvataggio: ' + (data.error || 'Errore sconosciuto'));
        }
    })
    .catch(error => {
        console.error('Errore:', error);
        alert('Errore di connessione');
    });
}

function calculateMortgagePayment(principal, annualRate, years) {
    const monthlyRate = annualRate / 100 / 12;
    const months = years * 12;
    if (monthlyRate === 0) return principal / months;
    return (principal * monthlyRate * Math.pow(1 + monthlyRate, months)) /
           (Math.pow(1 + monthlyRate, months) - 1);
}

function calculatePrincipalFromPayment(payment, annualRate, years) {
    const monthlyRate = annualRate / 100 / 12;
    const months = years * 12;
    if (monthlyRate === 0) return payment * months;
    return payment * (Math.pow(1 + monthlyRate, months) - 1) /
           (monthlyRate * Math.pow(1 + monthlyRate, months));
}

function getG53CACI(nucleo) {
    const map = { 1: 700, 2: 1000, 3: 1350, 4: 1700, 5: 2050, 6: 2400 };
    const n = Math.min(Math.max(parseInt(nucleo || 1, 10), 1), 6);
    return map[n] || 700;
}

function evaluateCACI(row, totalNetMonthlyBeforeMant, prospectData) {
    const G54 = calculateSussistencyCACI(
        prospectData.mortgage_amount,
        prospectData.duration_years,
        row.variant
    );

    const H20 = totalNetMonthlyBeforeMant;
    const H26 = prospectData.total_commitments || 0;
    const H33 = prospectData.total_maintenance || 0;

    const F53 = (prospectData.f53 != null) ? prospectData.f53 : 0.35;
    const G53 = (prospectData.g53 != null)
        ? prospectData.g53
        : getG53CACI(prospectData.family_members || 1);

    const K53 = calculateK53CACI(H20, H26, H33, G53, F53);
    const J54 = calculateJ54CACI(
        K53,
        prospectData.duration_years,
        prospectData.annual_rate,
        row.variant
    );

    const C44 = prospectData.annual_rate;
    const C45 = prospectData.duration_years;
    const C46 = prospectData.mortgage_payment;

    const monthlyRate = C44 / 12 / 100;
    const power = Math.pow(1 + monthlyRate, C45 * 12);
    const fattore = 1 / (1 - 1 / power);
    const numeratore = (G54 * C44 / 12 / 100 * fattore + C46);
    const rrMax = H20 > 0 ? (numeratore / H20) * 100 : 999;
    const rrInt = Math.round(rrMax);

    return {
        rr: rrInt + '%',
        sussistenza: formatCurrency(G54),
        rr_margin: (rrMax - prospectData.rr).toFixed(1) + '%',
        suss_margin: formatCurrency(J54 - prospectData.mortgage_amount),
        rata_max: formatCurrency(K53),
        mutuo_max: formatCurrency(J54),
        esito: (J54 >= prospectData.mortgage_amount && rrMax >= prospectData.rr) ? '✅' : '❌'
    };
}

function calculateSussistencyCACI(mortgageAmount, durationYears, variant) {
    const anniFactor = 0.002625 * durationYears;

    if (variant === 'CACI - Privati/Autonomi') {
        return mortgageAmount * (anniFactor + 0.014898);
    }
    if (variant === 'CACI - Dip. Pubblici') {
        return mortgageAmount * (anniFactor + 0.01146);
    }

    return mortgageAmount * (anniFactor + 0.014898);
}

function calculateSussistenzaMassimaCACI(familyNetMonthly, durationYears, annualRate, variant) {
    const L53 = calculateMortgagePayment(familyNetMonthly, annualRate, durationYears);
    const C45 = durationYears;
    let C21 = 0.014898;
    if (variant === 'CACI - Dip. Pubblici') C21 = 0.01146;
    const C19 = 0.002625;
    const D21 = 1;
    return L53 * 12 * C45 * C19 + C21 * D21 * L53;
}

function calculateK53CACI(H20, H26, H33, G53, F53) {
  const a = (F53 * (H20 - H26)) - H33;
  const b = (H20 - H26 - G53 - H33);
  const k = Math.min(a, b);
  return (k < 0) ? 0 : k;
}

function calculateJ54CACI(K53, durationYears, annualRate, variant) {
  const L53 = calculatePrincipalFromPayment(K53, annualRate, durationYears);

  const C19 = 0.002625;
  const D21 = 1;
  const C21 = (variant === 'CACI - Dip. Pubblici') ? 0.01146 : 0.014898;

  return (L53 * 12 * durationYears * C19) + (C21 * D21 * L53);
}

function formatCurrency(value) {
    return new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' }).format(value);
}

function calculateNetMonthlyIncome(annualIncome, bonus, irpef, additions, maintenance, cudDays) {
    const netAnnual = annualIncome + bonus - irpef - additions - (maintenance * 12);
    return (netAnnual / 12 / cudDays) * 365;
}

function initForm() {
    const tbody = document.getElementById('intestatari-tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    for (let i = 1; i <= INTESTATARI_COUNT; i++) {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${i}</td>
            <td><input type="text" id="first_name_${i}" placeholder="Nome" /></td>
            <td><input type="text" id="last_name_${i}" placeholder="Cognome" /></td>
            <td><input type="email" id="email_${i}" placeholder="email@email.it" /></td>
            <td><input type="number" id="birth_year_${i}" min="1940" max="2010" /></td>
            <td><span id="age_${i}">-</span></td>
            <td><span id="age_end_${i}">-</span></td>
            <td><span id="duration_max_${i}">-</span></td>
            <td>
                <select id="type_${i}">
                    <option value=""></option>
                    <option value="intestatario">Intestatario</option>
                    <option value="garante">Garante</option>
                </select>
            </td>
            <td><input type="number" id="cud_days_${i}" value="365" min="1" max="365" style="width:60px;" /></td>
            <td>
                <div style="display:flex; gap:4px; align-items:center;">
                    <input type="number" id="annual_income_${i}" step="100" />
                    <button type="button"
                            class="icon" 
                            onclick="openNetIncomeCalc(${i})"
                            title="Calcola reddito netto"
                            style="width:26px;height:26px;padding:0;font-size:14px;line-height:1;">
                        🧮
                    </button>
                </div>
            </td>
            <td><input type="number" id="bonus_${i}" value="0" /></td>
            <td><input type="number" id="irpef_${i}" value="0" /></td>
            <td><input type="number" id="additions_${i}" value="0" /></td>
            <td><input type="number" id="maintenance_${i}" value="0" /></td>
            <td><input type="number" id="commitments_${i}" value="0" /></td>
        `;
        tbody.appendChild(tr);

        ['first_name_', 'last_name_', 'email_', 'birth_year_', 'annual_income_'].forEach(prefix => {
            const el = document.getElementById(prefix + i);
            if (el) el.addEventListener('input', validateBorrowers);
        });
        const typeSel = document.getElementById(`type_${i}`);
        if (typeSel) typeSel.addEventListener('change', validateBorrowers);
    }
    document.querySelectorAll('input[id^="birth_year_"], #duration_years').forEach(el => {
        el.addEventListener('input', updateAges);
    });
}

function calculateAge(birthYear) { return new Date().getFullYear() - birthYear; }

function updateAges() {
    const durationYears = parseInt(document.getElementById('duration_years').value) || 0;
    for (let i = 1; i <= INTESTATARI_COUNT; i++) {
        const birthYear = parseInt(document.getElementById(`birth_year_${i}`).value) || 0;
        if (birthYear > 0) {
            const age = calculateAge(birthYear);
            const ageAtEnd = age + durationYears;
            document.getElementById(`age_${i}`).textContent = age;
            const ageEndSpan = document.getElementById(`age_end_${i}`);
            ageEndSpan.textContent = ageAtEnd;
            ageEndSpan.className = ageAtEnd > 80 ? 'age-warning' : '';
            if (i > 1) {
                const durationMax = ((age - 80) / -2) * 3;
                document.getElementById(`duration_max_${i}`).textContent =
                    durationMax > 0 ? Math.round(durationMax) : '-';
            } else {
                document.getElementById(`duration_max_${i}`).textContent = '-';
            }
        } else {
            document.getElementById(`age_${i}`).textContent = '-';
            document.getElementById(`age_end_${i}`).textContent = '-';
            document.getElementById(`age_end_${i}`).className = '';
            document.getElementById(`duration_max_${i}`).textContent = '-';
        }
    }
}

// Popup reddito netto ING
function openNetIncomeCalc(index) {
    CURRENT_PERSON_INDEX = index;

    const annualInput = document.getElementById('annual_income_' + index);
    const ral = parseFloat(annualInput?.value) || 0;

    if (ral > 0) {
        const baseGuess = ral / 13;
        document.getElementById('ni_base_pay').value = Math.round(baseGuess);
        document.getElementById('ni_months').value = 13;
    } else {
        document.getElementById('ni_base_pay').value = '';
        document.getElementById('ni_months').value = 12;
    }

    document.getElementById('ni_ral_out').textContent = '';
    document.getElementById('ni_net_month_out').textContent = '';
    document.getElementById('ni_net_year_out').textContent = '';

    const modal = document.getElementById('netIncomeModal');
    modal.style.display = 'flex';
}

function closeNetIncomeCalc() {
    document.getElementById('netIncomeModal').style.display = 'none';
    CURRENT_PERSON_INDEX = null;
}

function runNetIncomeCalc() {
    const basePay = parseFloat(document.getElementById('ni_base_pay').value) || 0;
    const months  = parseInt(document.getElementById('ni_months').value) || 12;
    const jobType = document.getElementById('ni_job_type').value;

    if (basePay <= 0 || months <= 0) {
        alert('Inserisci paga base e mensilità.');
        return;
    }

    const H17 = basePay * months;
    const I93 = H17;

    const C90 = (jobType === 'pubblico') ? 'Pubblico' : 'Privato';
    const I88 = (C90 === 'Pubblico') ? 0.0848 : 0.0919;

    const I89 = H17 * I88;
    const I90 = H17 - I89;

    function K98() { return I90 * 0.23; }
    function K99() { return 6440 + (I90 - 28000) * 0.35; }
    function K100() { return 14140 + (I90 - 50000) * 0.43; }

    function L90() { return 1955; }
    function L91() {
        const base = 1910 + 1190 * (28000 - I90) / 13000;
        return (I90 > 25000 && I90 <= 35000) ? (base + 65) : base;
    }
    function L92() {
        const base = 1910 * (50000 - I90) / 22000;
        return (I90 > 25000 && I90 <= 35000) ? (base + 65) : base;
    }

    const I91 =
        (I90 <= 28000) ? K98()
      : (I90 <= 50000) ? K99()
      : K100();

    const I94 =
        (I90 < 15000) ? L90()
      : (I90 <= 28000) ? L91()
      : (I90 <= 50000) ? L92()
      : 0;

    const I92 = I91 - I94;

    const C91 = (I94 < I91)
        ? ((I93 - I89 - I92) / 12)
        : ((I93 - I89) / 12);

    const nettoMensile = C91;
    const nettoAnnuo   = nettoMensile * 12;

    document.getElementById('ni_ral_out').textContent       = formatCurrency(H17);
    document.getElementById('ni_net_month_out').textContent = formatCurrency(nettoMensile);
    document.getElementById('ni_net_year_out').textContent  = formatCurrency(nettoAnnuo);
}

function calculateFeasibility() {
    let familyNetMonthly = 0, familyNetMonthlyBeforeMant = 0, familyCommitments = 0, familyMaintenance = 0, componentCount = 0;
    const intestatariData = [];
    const durationYears = parseInt(document.getElementById('duration_years').value) || 1;

    for (let i = 1; i <= INTESTATARI_COUNT; i++) {
        const first_name = document.getElementById(`first_name_${i}`).value.trim();
        const last_name  = document.getElementById(`last_name_${i}`).value.trim();
        const name = (first_name + ' ' + last_name).trim();

        const birthYear = parseInt(document.getElementById(`birth_year_${i}`).value) || 0;
        const type = document.getElementById(`type_${i}`).value;
        const annualIncome = parseFloat(document.getElementById(`annual_income_${i}`).value) || 0;
        const bonus = parseFloat(document.getElementById(`bonus_${i}`).value) || 0;
        const irpef = parseFloat(document.getElementById(`irpef_${i}`).value) || 0;
        const additions = parseFloat(document.getElementById(`additions_${i}`).value) || 0;
        const maintenance = parseFloat(document.getElementById(`maintenance_${i}`).value) || 0;
        const commitments = parseFloat(document.getElementById(`commitments_${i}`).value) || 0;
        const cudDays = parseFloat(document.getElementById(`cud_days_${i}`).value) || 365;

        if (name && type) {
            const age = calculateAge(birthYear);
            const ageAtEnd = age + durationYears;

            const netAnnualBeforeMant = annualIncome + bonus - irpef - additions;
            const netMonthlyBeforeMant = (netAnnualBeforeMant / 12 / cudDays) * 365;

            const netMonthly = calculateNetMonthlyIncome(
                annualIncome, bonus, irpef, additions, maintenance, cudDays
            );

            intestatariData.push({ name, age, ageAtEnd, netMonthly, maintenance, commitments });

            familyNetMonthly += netMonthly;
            familyNetMonthlyBeforeMant += netMonthlyBeforeMant;
            familyCommitments += commitments;
            familyMaintenance += maintenance;
            componentCount++;
        }
    }

    const propertyPrice = parseFloat(document.getElementById('property_price').value) || 0;
    const mortgageAmount = parseFloat(document.getElementById('mortgage_amount').value) || 0;
    const annualRate = parseFloat(document.getElementById('annual_rate').value) || 0;
    const mortgagePayment = calculateMortgagePayment(mortgageAmount, annualRate, durationYears);

    const subsistenceIncome = familyNetMonthly - familyMaintenance - familyCommitments - mortgagePayment;
    const ltv = propertyPrice > 0 ? (mortgageAmount / propertyPrice) * 100 : 0;
    const rr = familyNetMonthly > 0 ? (mortgagePayment / familyNetMonthly) * 100 : 0;
    const lti = familyNetMonthlyBeforeMant > 0 ? (mortgageAmount / familyNetMonthlyBeforeMant) : 0;

    const perizia80 = mortgageAmount > 0 ? (mortgageAmount / 0.80) : 0;
    const perizia70 = mortgageAmount > 0 ? (mortgageAmount / 0.70) : 0;
    const perizia95 = mortgageAmount > 0 ? (mortgageAmount / 0.95) : 0;
    const incomeForRatios = familyNetMonthlyBeforeMant;
    const totalIndeb = mortgagePayment + familyCommitments + familyMaintenance;
    const rr_indeb_ratio = incomeForRatios > 0 ? (totalIndeb / incomeForRatios) * 100 : 0;

    const denominatorRR_Mant = incomeForRatios - familyMaintenance;
    const rr_mant_indeb_ratio = denominatorRR_Mant > 0
        ? ((mortgagePayment + familyCommitments) / denominatorRR_Mant) * 100
        : 0;

    const chebanca_rr = familyNetMonthlyBeforeMant > 0
        ? (mortgagePayment / (familyNetMonthlyBeforeMant - familyCommitments - familyMaintenance)) * 100
        : 0;

    const resultsTable = document.getElementById('results-tbody');
    resultsTable.innerHTML = '';
    intestatariData.forEach(item => {
        const row = resultsTable.insertRow();
        row.innerHTML = `
            <td>${item.name}</td>
            <td>${item.age}</td>
            <td class="${item.ageAtEnd > 80 ? 'age-warning' : ''}">${item.ageAtEnd}</td>
            <td>${formatCurrency(item.netMonthly)}</td>
            <td>${formatCurrency(item.maintenance)}</td>
            <td>${formatCurrency(item.commitments)}</td>
        `;
    });

    document.getElementById('family_income').textContent = formatCurrency(familyNetMonthly);
    document.getElementById('family_maintenance').textContent = formatCurrency(familyMaintenance);
    document.getElementById('subsistence_income').textContent = formatCurrency(subsistenceIncome);
    document.getElementById('family_commitments').textContent = formatCurrency(familyCommitments);

    document.getElementById('mortgage_rate').textContent = formatCurrency(mortgagePayment);
    document.getElementById('ltv_value').textContent = ltv.toFixed(2) + '%';
    document.getElementById('lti_value').textContent = lti.toFixed(2) + '%';
    document.getElementById('rr_value').textContent = rr.toFixed(2) + '%';
    document.getElementById('rr_indeb_ratio').textContent = rr_indeb_ratio.toFixed(2) + '%';
    document.getElementById('rr_mant_indeb_ratio').textContent = rr_mant_indeb_ratio.toFixed(2) + '%';
    document.getElementById('chebanca_rr').textContent = chebanca_rr.toFixed(2) + '%';

    document.getElementById('perizia_80').textContent = perizia80 ? formatCurrency(perizia80) : '';
    document.getElementById('perizia_70').textContent = perizia70 ? formatCurrency(perizia70) : '';
    document.getElementById('perizia_95').textContent = perizia95 ? formatCurrency(perizia95) : '';

    const valuationBody = document.getElementById('valuation-tbody');
    if (valuationBody) {
        valuationBody.innerHTML = '';
        const redditoSuss = subsistenceIncome;
        let nucleoInput = parseInt(document.getElementById('family_members')?.value) || 0;
        if (nucleoInput < 1 || nucleoInput > 7) nucleoInput = 0;
        const nucleo = nucleoInput || componentCount || 1;
        let ltiLocal = propertyPrice > 0 ? (mortgageAmount / propertyPrice) * 100 : 0;

        SUSSISTENZE_DATA.forEach(row => {       
                const nomeBanca = row.nome_banca;
                const variant = row.variant || '';
                const fullName = variant ? (nomeBanca + ' ' + variant) : nomeBanca;

                const isCACI = variant.startsWith('CACI -');

                if (isCACI) {
                  try {
                     const p = {
                              mortgage_amount: mortgageAmount,
                              duration_years: durationYears,
                              annual_rate: annualRate,
                              mortgage_payment: mortgagePayment,
                              rr: rr,
                              total_commitments: familyCommitments,
                              total_maintenance: familyMaintenance,
                              family_members: nucleo,
                              g53: getG53CACI(nucleo),
                              f53: 0.35
                            };

                    const caci = evaluateCACI(row, familyNetMonthlyBeforeMant, p);
                    
                    const rrInt = Math.round(parseFloat(String(caci.rr).replace('%','').replace(',','.')) || 0);
                    const rrLabel = rrInt + '%';
                    const rr_limit_caci = 33-rrInt; 
                    const rrRed  = rr_limit_caci <= 0;
                    
                    const notFeasible = rrRed ;
                    const tr = valuationBody.insertRow();
                    const esito = notFeasible ? '<span style="color:#b71c1c;font-weight:700;">NON FATTIBILE</span>' : '<span style="color:#155724;font-weight:700;">FATTIBILE</span>';  
                    
                    tr.innerHTML = `
                      <td>${fullName}</td>
                      <td>${rrLabel}</td>
                      <td>${caci.sussistenza ?? '-'}</td>
                      <td>${caci.rr_margin ?? '-'}</td>
                      <td>${caci.suss_margin ?? '-'}</td>
                      <td>${caci.rata_max ?? '-'}</td>
                      <td>${caci.mutuo_max ?? '-'}</td>
                      <td${notFeasible ? ' class="red-cell"' : ''}>${esito}</td>
                    `;
                  } catch (e) {
                    console.error('ERRORE rendering CACI:', fullName, e);
                    const tr = valuationBody.insertRow();
                    tr.innerHTML = `<td colspan="8" style="color:#b71c1c;font-weight:700;">CACI ERRORE: ${fullName}</td>`;
                  }

                  return;
                }
                                           
            let limiteRR = null;
            if (row.rr_max !== null && row.rr_max !== '' && !isNaN(row.rr_max)) {
                limiteRR = Number(row.rr_max);
            } else {
                const sogliaMin = Number(row.soglia_min || 0);
                const sogliaMax = Number(row.soglia_max || 0);
                const valRRMin  = Number(row.valore_rr_min || 0);
                const valRRMid  = Number(row.valore_rr_mid || 0);
                const valRRMax  = Number(row.valore_rr_max || 0);

                if (redditoSuss <= sogliaMin) {
                    limiteRR = valRRMin;
                } else if (redditoSuss <= sogliaMax) {
                    limiteRR = valRRMid;
                } else {
                    limiteRR = valRRMax;
                }
            }

            let sussVal = null;
            const n = Math.min(Math.max(nucleo, 1), 7);
            switch (n) {
                case 1: sussVal = Number(row.sussistenza_1 || 0); break;
                case 2: sussVal = Number(row.sussistenza_2 || 0); break;
                case 3: sussVal = Number(row.sussistenza_3 || 0); break;
                case 4: sussVal = Number(row.sussistenza_4 || 0); break;
                case 5: sussVal = Number(row.sussistenza_5 || 0); break;
                case 6: sussVal = Number(row.sussistenza_6 || 0); break;
                case 7: sussVal = Number(row.sussistenza_7 || 0); break;
            }

            const hasRrLimit =
                row.rr_max != null && row.rr_max !== '' && !isNaN(row.rr_max) ||
                (row.valore_rr_min != null && row.valore_rr_min !== '' && !isNaN(row.valore_rr_min)) ||
                (row.valore_rr_mid != null && row.valore_rr_mid !== '' && !isNaN(row.valore_rr_mid)) ||
                (row.valore_rr_max != null && row.valore_rr_max !== '' && !isNaN(row.valore_rr_max));

            const margineRR = (hasRrLimit && limiteRR !== null) ? (limiteRR - rr) : null;
            const margineSuss = sussVal !== null ? (redditoSuss - sussVal) : null;

            let rataMax = null, mutuoMax = null;
            if (hasRrLimit && limiteRR !== null && familyNetMonthly > 0) {
                rataMax = (limiteRR / 100) * familyNetMonthly;
                mutuoMax = calculatePrincipalFromPayment(rataMax, annualRate, durationYears);
            }

            const ltiRed = ltiLocal > 83.99;
            const rrRed = hasRrLimit && limiteRR !== null && (rr > limiteRR);
            const sussRed = (sussVal !== null) && (redditoSuss < sussVal);
            const notFeasible = ltiRed || rrRed || sussRed;

            const esito = notFeasible
                ? '<span style="color:#b71c1c;font-weight:700;">NON FATTIBILE</span>'
                : '<span style="color:#155724;font-weight:700;">FATTIBILE</span>';

            const tr = valuationBody.insertRow();
            if (notFeasible) tr.className = 'not-feasible-row';
            tr.innerHTML = `
                <td>${fullName}</td>
                <td${rrRed ? ' class="red-cell"' : ''}>${limiteRR !== null ? limiteRR.toFixed(0) + '%' : '-'}</td>
                <td${sussRed ? ' class="red-cell"' : ''}>${sussVal ? formatCurrency(sussVal) : '-'}</td>
                <td${rrRed ? ' class="red-cell"' : ''}>${margineRR !== null ? margineRR.toFixed(2) + '%' : '-'}</td>
                <td${sussRed ? ' class="red-cell"' : ''}>${margineSuss !== null ? formatCurrency(margineSuss) : '-'}</td>
                <td>${rataMax !== null ? formatCurrency(rataMax) : '-'}</td>
                <td>${mutuoMax !== null ? formatCurrency(mutuoMax) : '-'}</td>
                <td${notFeasible ? ' class="red-cell"' : ''}>${esito}</td>
            `;
        });
    }

    document.getElementById('results').classList.add('show');
}

function resetForm() {
    document.getElementById('results').classList.remove('show');
    initForm();
    updateAges();
    validateBorrowers();
}

async function loadProspect() {
    const idInput = document.getElementById('load_prospect_id');
    const id = parseInt(idInput.value, 10);

    if (!id) {
        alert('Inserisci un ID prospect valido');
        return;
    }

    try {
        const url = window.location.pathname + '?action=load_prospect&id=' + encodeURIComponent(id);
        const resp = await fetch(url);
        const data = await resp.json();
        console.log('Loaded prospect:', data);

        if (!data.success) {
            alert(data.error || 'Prospect non trovato');
            return;
        }

        const p = data.prospect;

        // Dati mutuo / immobile
        document.getElementById('property_price').value  = p.property_price;
        document.getElementById('mortgage_amount').value = p.mortgage_amount;
        document.getElementById('duration_years').value  = p.duration_years;
        document.getElementById('annual_rate').value     = p.annual_rate;
        document.getElementById('family_members').value  = p.family_members;

        // Svuota tutte le righe intestatari
        for (let i = 1; i <= INTESTATARI_COUNT; i++) {
            document.getElementById(`first_name_${i}`).value    = '';
            document.getElementById(`last_name_${i}`).value     = '';
            document.getElementById(`email_${i}`).value         = '';
            document.getElementById(`birth_year_${i}`).value    = '';
            document.getElementById(`type_${i}`).value          = '';
            document.getElementById(`annual_income_${i}`).value = '';
            document.getElementById(`bonus_${i}`).value         = '';
            document.getElementById(`irpef_${i}`).value         = '';
            document.getElementById(`additions_${i}`).value     = '';
            document.getElementById(`maintenance_${i}`).value   = '';
            document.getElementById(`commitments_${i}`).value   = '';
            document.getElementById(`cud_days_${i}`).value      = 365;
        }

        // Popola dalla borrowers_json salvata (un oggetto = CQS con cf_piva/importo_richiesto/durata_mesi, o tipo 'cqs')
        const borrowers = Array.isArray(p.borrowers_data) ? p.borrowers_data : [];
        const hasCqsFields = (b) => b.tipo === 'cqs' || b.cf_piva != null || b.importo_richiesto != null || b.durata_mesi != null;
        const borrowersMutuo = borrowers.filter(b => !hasCqsFields(b));
        const borrowersCqs   = borrowers.filter(hasCqsFields);

        borrowersMutuo.slice(0, INTESTATARI_COUNT).forEach((b, idx) => {
            const i = idx + 1;
            document.getElementById(`first_name_${i}`).value    = b.first_name   || '';
            document.getElementById(`last_name_${i}`).value     = b.last_name    || '';
            document.getElementById(`email_${i}`).value         = b.email        || '';
            document.getElementById(`birth_year_${i}`).value    = b.birthYear    || '';
            document.getElementById(`type_${i}`).value          = b.type         || '';
            document.getElementById(`annual_income_${i}`).value = b.annualIncome || '';
            document.getElementById(`bonus_${i}`).value         = b.bonus        || '';
            document.getElementById(`irpef_${i}`).value         = b.irpef        || '';
            document.getElementById(`additions_${i}`).value     = b.additions    || '';
            document.getElementById(`maintenance_${i}`).value   = b.maintenance  || '';
            document.getElementById(`commitments_${i}`).value   = b.commitments  || '';
            document.getElementById(`cud_days_${i}`).value      = 365;
        });
        if (borrowersCqs.length > 0 && borrowersMutuo.length === 0) {
            const b = borrowersCqs[0];
            document.getElementById('first_name_1').value = b.first_name || '';
            document.getElementById('last_name_1').value  = b.last_name  || '';
            document.getElementById('email_1').value      = b.email     || '';
            document.getElementById('birth_year_1').value = b.birthYear  || '';
            document.getElementById('type_1').value       = b.type      || 'intestatario';
            document.getElementById('annual_income_1').value = b.annualIncome || '';
            document.getElementById('bonus_1').value       = b.bonus     ?? '';
            document.getElementById('irpef_1').value      = b.irpef     ?? '';
            document.getElementById('additions_1').value = b.additions ?? '';
            document.getElementById('maintenance_1').value = b.maintenance ?? '';
            document.getElementById('commitments_1').value = b.commitments ?? '';
            document.getElementById('cud_days_1').value  = b.cudDays ?? b.cud_days ?? 365;
        }

        populateCqsIntestatari(borrowersCqs);

        validateBorrowers();
        updateAges();
        calculateFeasibility();

        alert('Prospect caricato.');
    } catch (e) {
        console.error('Errore loadProspect:', e);
        alert('Errore nel caricamento del prospect');
    }
}
function resetCqsForm() {
    initCqsForm();
}

function initCqsForm() {
    // Un solo beneficiario CQS: reset campi (form già in DOM)
    const ids = ['cqs_first_name_1', 'cqs_last_name_1', 'cqs_email_1', 'cqs_cf_piva_1', 'cqs_birth_year_1', 'cqs_type_1',
        'cqs_cud_days_1', 'cqs_annual_income_1', 'cqs_bonus_1', 'cqs_irpef_1', 'cqs_additions_1', 'cqs_maintenance_1', 'cqs_commitments_1',
        'cqs_importo_richiesto', 'cqs_durata_mesi'];
    const defaults = { cqs_type_1: '', cqs_cud_days_1: '365', cqs_bonus_1: '0', cqs_irpef_1: '0', cqs_additions_1: '0', cqs_maintenance_1: '0', cqs_commitments_1: '0', cqs_durata_mesi: '60' };
    ids.forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = defaults[id] !== undefined ? defaults[id] : '';
    });
}
	
function syncCqsToMutuoFields({ overwrite = false } = {}) {
   for (let i = 1; i <= INTESTATARI_COUNT; i++) {
    const cFirst = (document.getElementById(`cqs_first_name_${i}`)?.value || '').trim();
    const cLast  = (document.getElementById(`cqs_last_name_${i}`)?.value || '').trim();
    const cEmail = (document.getElementById(`cqs_email_${i}`)?.value || '').trim();
    const cBirth = (document.getElementById(`cqs_birth_year_${i}`)?.value || '').trim();

    const cIncome = (document.getElementById(`cqs_annual_income_${i}`)?.value || '').trim();
    const cBonus  = document.getElementById(`cqs_bonus_${i}`)?.value || '0';
    const cIrpef  = document.getElementById(`cqs_irpef_${i}`)?.value || '0';
    const cAdd    = document.getElementById(`cqs_additions_${i}`)?.value || '0';
    const cMant   = document.getElementById(`cqs_maintenance_${i}`)?.value || '0';
    const cComm   = document.getElementById(`cqs_commitments_${i}`)?.value || '0';
    const cCud    = document.getElementById(`cqs_cud_days_${i}`)?.value || '365';

    // riga CQS vuota → skip
    if (!cFirst && !cLast && !cEmail && !cIncome) continue;

    const mFirst = (document.getElementById(`first_name_${i}`)?.value || '').trim();
    const mLast  = (document.getElementById(`last_name_${i}`)?.value || '').trim();
    const mEmail = (document.getElementById(`email_${i}`)?.value || '').trim();
    const mInc   = (document.getElementById(`annual_income_${i}`)?.value || '').trim();
    const mutuoHasData = (mFirst || mLast || mEmail || mInc);

    if (!overwrite && mutuoHasData) continue;

    document.getElementById(`first_name_${i}`).value = cFirst;
    document.getElementById(`last_name_${i}`).value  = cLast;
    document.getElementById(`email_${i}`).value      = cEmail;
    document.getElementById(`birth_year_${i}`).value = cBirth;

    // type mutuo: default
    document.getElementById(`type_${i}`).value = 'intestatario';

    document.getElementById(`annual_income_${i}`).value = cIncome;
    document.getElementById(`bonus_${i}`).value         = cBonus;
    document.getElementById(`irpef_${i}`).value         = cIrpef;
    document.getElementById(`additions_${i}`).value     = cAdd;
    document.getElementById(`maintenance_${i}`).value   = cMant;
    document.getElementById(`commitments_${i}`).value   = cComm;
    document.getElementById(`cud_days_${i}`).value      = cCud;
  }

  updateAges();
  validateBorrowers();
}

function populateCqsIntestatari(list) {
    const b = Array.isArray(list) && list.length > 0 ? list[0] : {};
    const set = (id, val) => { const el = document.getElementById(id); if (el) el.value = val !== undefined && val !== null ? String(val) : ''; };
    set('cqs_first_name_1', b.first_name);
    set('cqs_last_name_1', b.last_name);
    set('cqs_email_1', b.email);
    set('cqs_cf_piva_1', b.cf_piva);
    set('cqs_birth_year_1', b.birthYear ?? b.birth_year);
    set('cqs_type_1', b.type);
    set('cqs_annual_income_1', b.annualIncome ?? b.annual_income);
    set('cqs_bonus_1', b.bonus ?? 0);
    set('cqs_irpef_1', b.irpef ?? 0);
    set('cqs_additions_1', b.additions ?? 0);
    set('cqs_maintenance_1', b.maintenance ?? 0);
    set('cqs_commitments_1', b.commitments ?? 0);
    set('cqs_cud_days_1', b.cudDays ?? b.cud_days ?? 365);
    set('cqs_importo_richiesto', b.importo_richiesto ?? '');
    set('cqs_durata_mesi', b.durata_mesi ?? 60);
}

document.addEventListener('DOMContentLoaded', () => {
    if (typeof initForm === 'function') {
        initForm();
    }
    initCqsForm();
    if (typeof updateAges === 'function') {
        updateAges();
    }
    if (typeof loadSussistenze === 'function') {
        loadSussistenze();
    }
    if (typeof validateBorrowers === 'function') {
        validateBorrowers();
    }
    showTab('mutuo');
});
</script>
</body>
</html>
<?php