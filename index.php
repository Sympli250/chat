<?php
session_start();

// ⚡ Configuration optimisée CROM
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Charger la configuration depuis .env si disponible
if (file_exists(__DIR__ . '/.env')) {
    $env = parse_ini_file(__DIR__ . '/.env');
    foreach ($env as $key => $value) {
        $_ENV[$key] = $value;
    }
}

// Configuration avec les nouvelles valeurs
$BASE_URL = $_ENV['LLM_BASE_URL'] ?? 'http://llm.symplissime.fr:4004';
$API_KEY = $_ENV['LLM_API_KEY'] ?? 'WMPYH03-FDCM21W-GCV7JWT-BQF9WRV';
$DEFAULT_WORKSPACE = $_ENV['DEFAULT_WORKSPACE'] ?? 'crom';
$CURRENT_USER = $_ENV['CURRENT_USER'] ?? 'crom';
$USER_PASSWORD = $_ENV['USER_PASSWORD'] ?? 'crom123456';
$MAX_MESSAGE_LENGTH = $_ENV['MAX_MESSAGE_LENGTH'] ?? 5000;
$REQUEST_TIMEOUT = $_ENV['REQUEST_TIMEOUT'] ?? 30;

// ⚡ Fonction pour créer une nouvelle connexion cURL optimisée
function createOptimizedCurl() {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($ch, CURLOPT_ENCODING, 'gzip,deflate');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TCP_NODELAY, true);
    return $ch;
}

// ⚡ Handle file upload pour AnythingLLM
if (isset($_POST['action']) && $_POST['action'] === 'upload') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        if (!isset($_FILES['file'])) {
            throw new Exception('Aucun fichier uploadé');
        }

        $file = $_FILES['file'];
        $workspace = $_POST['workspace'] ?? $DEFAULT_WORKSPACE;

        // Validation
        $maxSize = 10 * 1024 * 1024; // 10MB
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf',
                        'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'text/plain', 'text/csv'];

        if ($file['size'] > $maxSize) {
            throw new Exception('Fichier trop volumineux (max 10MB)');
        }

        if (!in_array($file['type'], $allowedTypes)) {
            throw new Exception('Type de fichier non autorisé');
        }

        // Forward vers AnythingLLM
        $url = "$BASE_URL/api/v1/workspace/$workspace/upload";
        $cfile = new CURLFile($file['tmp_name'], $file['type'], $file['name']);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ['file' => $cfile]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $API_KEY]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception('Upload échoué: ' . ($response ?: "HTTP $httpCode"));
        }

        $result = json_decode($response, true);

        echo json_encode([
            'success' => true,
            'filename' => $file['name'],
            'size' => $file['size'],
            'type' => $file['type'],
            'document_id' => $result['document_id'] ?? null
        ]);

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ⚡ Test endpoint pour debug
if (isset($_GET['test'])) {
    header('Content-Type: application/json; charset=utf-8');

    $ch = createOptimizedCurl();
    $url = "$BASE_URL/api/v1/workspace/$DEFAULT_WORKSPACE/chat";

    $payload = [
        'message' => 'test de connexion',
        'mode' => 'chat',
        'sessionId' => 'test-' . time()
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $API_KEY,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    echo json_encode([
        'test' => true,
        'url' => $url,
        'payload' => $payload,
        'httpCode' => $httpCode,
        'curlError' => $curlError,
        'response' => json_decode($response, true) ?? $response
    ], JSON_PRETTY_PRINT);
    exit;
}

// ⚡ Handle chat optimisé
if (isset($_POST['action']) && $_POST['action'] === 'chat') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $message = $_POST['message'] ?? '';
        $workspace = $_POST['workspace'] ?? $DEFAULT_WORKSPACE;

        if (empty($message) || strlen($message) > $MAX_MESSAGE_LENGTH) {
            throw new Exception('Message invalide');
        }

        $ch = createOptimizedCurl();
        $url = "$BASE_URL/api/v1/workspace/$workspace/chat";

        // Préfixer le message pour forcer la réponse en français
        $frenchInstruction = "INSTRUCTION CRITIQUE: Tu DOIS répondre EXCLUSIVEMENT en français, quelle que soit la langue de la question posée. Ne réponds JAMAIS en anglais ou dans une autre langue.\n\nQuestion de l'utilisateur: ";
        $messageWithPrompt = $frenchInstruction . $message;

        $payload = [
            'message' => $messageWithPrompt,
            'mode' => 'chat',
            'sessionId' => session_id(),
            'attachments' => []
        ];

        // Log pour debug
        error_log("Chat request to $url with payload: " . json_encode($payload));

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $API_KEY,
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, $REQUEST_TIMEOUT);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        if ($curlError) {
            throw new Exception("Erreur cURL: $curlError");
        }

        if ($httpCode !== 200) {
            // Log detailed error
            error_log("AnythingLLM Error - HTTP $httpCode");
            error_log("Response body: " . substr($response, 0, 500));

            // Try to parse error response
            $errorData = json_decode($response, true);

            if ($errorData && isset($errorData['error'])) {
                $errorMsg = $errorData['error'];
            } elseif ($errorData && isset($errorData['message'])) {
                $errorMsg = $errorData['message'];
            } else {
                $errorMsg = "HTTP $httpCode - " . substr($response, 0, 100);
            }

            throw new Exception($errorMsg);
        }

        $data = json_decode($response, true);

        if ($data && isset($data['textResponse'])) {
            echo json_encode([
                'success' => true,
                'message' => $data['textResponse']
            ]);
        } elseif ($data && isset($data['error'])) {
            // Handle API-level errors
            throw new Exception("Erreur LLM: " . $data['error']);
        } else {
            error_log("Invalid response from AnythingLLM: $response");
            throw new Exception('Réponse invalide du serveur LLM');
        }

    } catch (Exception $e) {
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit;
}

// ⚡ Handle feedback
if (isset($_POST['action']) && $_POST['action'] === 'feedback') {
    header('Content-Type: application/json');

    $entry = [
        'question' => $_POST['question'] ?? '',
        'answer' => $_POST['answer'] ?? '',
        'date' => date('Y-m-d H:i:s')
    ];

    $file = __DIR__ . '/validated_responses.json';
    $data = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    $data[] = $entry;
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo json_encode(['success' => true]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="language" content="French">
    <meta http-equiv="Content-Language" content="fr">
    <title>CROM - Assistant IA Déontologie Médicale 🇫🇷</title>

    <style>
        /* 🎨 THÈME CROM INTÉGRÉ */
        :root {
            /* Palette CROM Officielle */
            --crom-bleu-principal: #003d7a;
            --crom-bleu-secondaire: #0066cc;
            --crom-bleu-clair: #4d94ff;
            --crom-bleu-tres-clair: #e6f2ff;
            --crom-vert-medical: #006633;
            --crom-vert-hover: #00994d;
            --crom-rouge-urgent: #cc0000;
            --crom-orange-attention: #ff9900;
            --crom-gris-fonce: #333333;
            --crom-gris-moyen: #666666;
            --crom-gris-clair: #999999;
            --crom-gris-tres-clair: #f8f9fa;
            --crom-gris-bordure: #e0e0e0;
            --crom-blanc: #ffffff;

            /* Spacing */
            --spacing-xs: 4px;
            --spacing-sm: 8px;
            --spacing-md: 16px;
            --spacing-lg: 24px;
            --spacing-xl: 32px;

            /* Typography */
            --font-sans: Arial, Helvetica, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-size-base: 14px;
            --font-size-small: 12px;
            --font-size-large: 16px;
            --font-size-title: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-sans);
            font-size: var(--font-size-base);
            line-height: 1.6;
            color: var(--crom-gris-fonce);
            background: var(--crom-blanc);
            height: 100vh;
            overflow: hidden;
        }

        .chat-container {
            display: flex;
            flex-direction: column;
            height: 100vh;
        }

        /* Header */
        .chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px var(--spacing-lg);
            background: linear-gradient(135deg, #ffffff 0%, #f0f8ff 100%);
            border-bottom: 3px solid var(--crom-bleu-principal);
            box-shadow: 0 3px 15px rgba(0, 61, 122, 0.15);
            min-height: 64px;
            position: relative;
        }

        .chat-header::after {
            content: '';
            position: absolute;
            bottom: -3px;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--crom-bleu-principal), var(--crom-bleu-secondaire), var(--crom-bleu-principal));
        }

        .header-left {
            display: flex;
            align-items: center;
            gap: 12px;
            flex: 1;
        }

        .logo {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--crom-bleu-principal), var(--crom-bleu-secondaire));
            color: var(--crom-blanc);
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 10px;
            font-size: 24px;
            box-shadow: 0 2px 6px rgba(0, 61, 122, 0.2);
            flex-shrink: 0;
        }

        .brand-info {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            flex-wrap: wrap;
        }

        .brand-title {
            font-size: 17px;
            font-weight: bold;
            color: var(--crom-bleu-principal);
            letter-spacing: -0.3px;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .brand-subtitle {
            font-size: 13px;
            color: var(--crom-gris-moyen);
            font-weight: normal;
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: nowrap;
        }

        .lang-badge {
            background: var(--crom-bleu-principal);
            color: var(--crom-blanc);
            padding: 3px 7px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .demo-badge {
            background: var(--crom-orange-attention);
            color: var(--crom-blanc);
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            animation: pulse 2s infinite;
            flex-shrink: 0;
        }

        .powered-by {
            font-size: 13px;
            color: var(--crom-gris-moyen);
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .powered-by a {
            color: var(--crom-vert-medical);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
        }

        .powered-by a:hover {
            color: var(--crom-vert-hover);
            text-decoration: underline;
        }

        .starware-link {
            font-weight: 700;
            color: var(--crom-gris-fonce);
            background: linear-gradient(135deg, var(--crom-bleu-principal), var(--crom-bleu-secondaire));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: 0.5px;
        }

        .separator {
            color: var(--crom-gris-bordure);
            font-weight: 300;
        }

        .header-actions {
            display: flex;
            gap: var(--spacing-sm);
        }

        .control-btn {
            width: 36px;
            height: 36px;
            background: var(--crom-blanc);
            border: 1px solid var(--crom-gris-bordure);
            border-radius: 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .control-btn:hover {
            background: var(--crom-bleu-tres-clair);
            border-color: var(--crom-bleu-secondaire);
            transform: translateY(-1px);
        }

        /* Messages */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: var(--spacing-md);
            background: linear-gradient(180deg, var(--crom-gris-tres-clair) 0%, var(--crom-blanc) 100%);
            scroll-behavior: smooth;
            display: flex;
            justify-content: center;
        }

        .messages-container {
            width: 100%;
            max-width: 900px;
            margin: 0 auto;
        }

        .message-wrapper {
            display: flex;
            flex-direction: column;
            margin-bottom: var(--spacing-md);
            animation: fadeIn 0.3s ease;
            width: 100%;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(15px) scale(0.98); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }

        @keyframes pulse {
            0%, 100% {
                opacity: 1;
                transform: scale(1);
            }
            50% {
                opacity: 0.8;
                transform: scale(1.05);
            }
        }

        .message-wrapper.user {
            align-items: flex-end;
        }

        .message-wrapper.assistant {
            align-items: flex-start;
        }

        .message-content-wrapper {
            display: flex;
            flex-direction: column;
            max-width: 80%;
            width: auto;
        }

        .message-bubble {
            padding: 10px 14px;
            border-radius: 18px;
            word-wrap: break-word;
            word-break: break-word;
            overflow-wrap: break-word;
            white-space: pre-wrap;
            line-height: 1.4;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            transition: transform 0.2s ease;
            position: relative;
        }

        .message-bubble:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .user .message-bubble {
            background: linear-gradient(135deg, var(--crom-bleu-principal) 0%, var(--crom-bleu-secondaire) 100%);
            color: var(--crom-blanc);
            border-bottom-right-radius: 4px;
        }

        .assistant .message-bubble {
            background: var(--crom-blanc);
            border: 1px solid var(--crom-gris-bordure);
            color: var(--crom-gris-fonce);
            border-bottom-left-radius: 4px;
        }

        /* Amélioration formatage du texte dans les bulles */
        .message-bubble p {
            margin: 0 0 6px 0;
        }

        .message-bubble p:last-child {
            margin-bottom: 0;
        }

        .message-bubble h1,
        .message-bubble h2,
        .message-bubble h3 {
            margin: 8px 0 6px 0;
            font-weight: 600;
        }

        .message-bubble h1 { font-size: 1.25em; }
        .message-bubble h2 { font-size: 1.15em; }
        .message-bubble h3 { font-size: 1.1em; }

        .message-bubble ul,
        .message-bubble ol {
            margin: 6px 0;
            padding-left: 20px;
        }

        .message-bubble li {
            margin: 3px 0;
            line-height: 1.4;
        }

        .message-bubble code {
            background: rgba(0, 0, 0, 0.1);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }

        .user .message-bubble code {
            background: rgba(255, 255, 255, 0.2);
        }

        .message-bubble strong {
            font-weight: 600;
        }

        .message-bubble em {
            font-style: italic;
        }

        .message-bubble a {
            color: inherit;
            text-decoration: underline;
            opacity: 0.9;
            transition: opacity 0.2s;
        }

        .message-bubble a:hover {
            opacity: 1;
            text-decoration-thickness: 2px;
        }

        .user .message-bubble a {
            color: var(--crom-blanc);
        }

        .assistant .message-bubble a {
            color: var(--crom-bleu-secondaire);
            font-weight: 500;
        }

        /* Actions sur les messages */
        .message-actions {
            display: flex;
            gap: 6px;
            margin-top: 6px;
            opacity: 0;
            transition: opacity 0.2s;
        }

        .message-wrapper:hover .message-actions {
            opacity: 1;
        }

        .action-icon {
            width: 28px;
            height: 28px;
            background: var(--crom-blanc);
            border: 1px solid var(--crom-gris-bordure);
            border-radius: 6px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
            transition: all 0.2s;
        }

        .action-icon:hover {
            background: var(--crom-bleu-tres-clair);
            border-color: var(--crom-bleu-secondaire);
            transform: translateY(-1px);
        }

        .action-icon:active {
            transform: scale(0.95);
        }

        .action-icon.voted {
            background: var(--crom-bleu-secondaire);
            color: var(--crom-blanc);
            border-color: var(--crom-bleu-secondaire);
        }

        /* Message d'accueil spécial */
        .welcome-message {
            background: linear-gradient(135deg, var(--crom-blanc) 0%, var(--crom-bleu-tres-clair) 100%) !important;
            border: 2px solid var(--crom-bleu-secondaire) !important;
            box-shadow: 0 3px 10px rgba(0, 61, 122, 0.12) !important;
            max-width: 100% !important;
        }

        .welcome-message h2 {
            color: var(--crom-bleu-principal);
            font-size: 1.3em;
            margin: 0 0 8px 0;
            padding-bottom: 6px;
            border-bottom: 2px solid var(--crom-bleu-clair);
        }

        .welcome-message h3 {
            color: var(--crom-bleu-secondaire);
            font-size: 1.05em;
            margin: 10px 0 6px 0;
        }

        .welcome-message ul {
            background: var(--crom-blanc);
            padding: 8px 8px 8px 24px;
            border-radius: 8px;
            margin: 6px 0;
        }

        .welcome-message li {
            margin: 4px 0;
            line-height: 1.4;
        }

        .welcome-message p:last-child {
            margin-top: 10px;
            padding: 8px;
            background: rgba(0, 102, 204, 0.06);
            border-radius: 6px;
            border-left: 3px solid var(--crom-bleu-secondaire);
        }

        /* Suggestions rapides */
        .quick-suggestions {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin: 10px 0 6px 0;
        }

        .suggestion-chip {
            display: inline-block;
            padding: 6px 12px;
            background: var(--crom-blanc);
            border: 1.5px solid var(--crom-bleu-secondaire);
            border-radius: 16px;
            color: var(--crom-bleu-secondaire);
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s ease;
            user-select: none;
        }

        .suggestion-chip:hover {
            background: var(--crom-bleu-secondaire);
            color: var(--crom-blanc);
            transform: translateY(-1px);
            box-shadow: 0 2px 6px rgba(0, 102, 204, 0.2);
        }

        .suggestion-chip:active {
            transform: translateY(0);
        }

        .message-time {
            font-size: var(--font-size-small);
            color: var(--crom-gris-clair);
            margin-top: 4px;
            padding: 0 4px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .user .message-time {
            justify-content: flex-end;
        }

        .assistant .message-time {
            justify-content: flex-start;
        }

        /* Typing indicator */
        .typing-indicator {
            display: none;
            padding: var(--spacing-sm) var(--spacing-md);
            background: var(--crom-blanc);
            border: 1px solid var(--crom-gris-bordure);
            border-radius: 18px;
            border-bottom-left-radius: 4px;
            width: fit-content;
            margin: 0 var(--spacing-lg) var(--spacing-md) var(--spacing-lg);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
            align-items: center;
            gap: var(--spacing-xs);
        }

        .typing-indicator::before {
            content: '⚕️';
            margin-right: 4px;
            font-size: 14px;
        }

        .typing-indicator.show {
            display: flex;
            animation: fadeIn 0.3s ease;
        }

        .typing-dot {
            width: 8px;
            height: 8px;
            background: var(--crom-bleu-secondaire);
            border-radius: 50%;
            animation: typing 1.4s infinite ease-in-out;
        }

        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }

        @keyframes typing {
            0%, 60%, 100% {
                transform: translateY(0) scale(1);
                opacity: 0.5;
            }
            30% {
                transform: translateY(-8px) scale(1.2);
                opacity: 1;
            }
        }

        /* Input zone */
        .chat-input-container {
            padding: var(--spacing-lg);
            background: linear-gradient(135deg, #ffffff 0%, #f0f8ff 100%);
            border-top: 3px solid var(--crom-bleu-principal);
            box-shadow: 0 -3px 15px rgba(0, 61, 122, 0.1);
            position: relative;
        }

        .chat-input-container::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--crom-bleu-principal), var(--crom-bleu-secondaire), var(--crom-bleu-principal));
        }

        .input-form {
            display: flex;
            gap: 8px;
            background: var(--crom-blanc);
            border: 2px solid var(--crom-bleu-secondaire);
            border-radius: 28px;
            padding: 6px;
            transition: all 0.3s;
            box-shadow: 0 2px 8px rgba(0, 61, 122, 0.08);
        }

        .input-form:focus-within:not(.disabled) {
            border-color: var(--crom-bleu-principal);
            background: var(--crom-blanc);
            box-shadow: 0 4px 16px rgba(0, 61, 122, 0.2);
            transform: translateY(-1px);
        }

        .input-form.disabled {
            opacity: 0.6;
            background: var(--crom-gris-tres-clair);
            border-color: var(--crom-gris-clair);
            box-shadow: none;
        }

        .attach-btn {
            width: 36px;
            height: 36px;
            background: transparent;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            transition: all 0.2s;
            border-radius: 50%;
        }

        .attach-btn:hover:not(:disabled) {
            background: var(--crom-bleu-tres-clair);
        }

        .attach-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }

        .message-input {
            flex: 1;
            background: transparent;
            border: none;
            outline: none;
            font-family: var(--font-sans);
            font-size: var(--font-size-base);
            color: var(--crom-gris-fonce);
            padding: var(--spacing-xs) 0;
        }

        .message-input::placeholder {
            color: var(--crom-gris-clair);
        }

        .message-input:disabled {
            cursor: not-allowed;
        }

        .send-button {
            width: 36px;
            height: 36px;
            background: var(--crom-bleu-principal);
            color: var(--crom-blanc);
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s;
        }

        .send-button:hover:not(:disabled) {
            background: var(--crom-vert-medical);
            transform: scale(1.08);
            box-shadow: 0 4px 12px rgba(0, 102, 51, 0.3);
        }

        .send-button:active:not(:disabled) {
            transform: scale(0.95);
        }

        .send-button:disabled {
            background: var(--crom-gris-clair);
            cursor: not-allowed;
            opacity: 0.5;
        }

        /* Toast */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: var(--spacing-sm) var(--spacing-md);
            border-radius: 8px;
            color: var(--crom-blanc);
            z-index: 1000;
            transform: translateX(400px);
            opacity: 0;
            transition: all 0.3s;
            max-width: 350px;
        }

        .toast.show {
            transform: translateX(0);
            opacity: 1;
        }

        .toast.success { background: var(--crom-vert-medical); }
        .toast.error { background: var(--crom-rouge-urgent); }
        .toast.warning { background: var(--crom-orange-attention); }
        .toast.info { background: var(--crom-bleu-principal); }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 10px;
        }

        ::-webkit-scrollbar-track {
            background: var(--crom-gris-tres-clair);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--crom-bleu-secondaire);
            border-radius: 5px;
            border: 2px solid var(--crom-gris-tres-clair);
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--crom-bleu-principal);
        }

        /* Mobile responsive */
        @media (max-width: 1200px) {
            .brand-subtitle,
            .separator:nth-last-child(-n+4) {
                display: none;
            }

            .powered-by {
                display: none;
            }
        }

        @media (max-width: 768px) {
            .chat-header {
                padding: 8px 12px;
            }

            .logo {
                width: 40px;
                height: 40px;
                font-size: 22px;
            }

            .brand-title {
                font-size: 15px;
            }

            .demo-badge {
                font-size: 9px;
                padding: 2px 6px;
            }

            .chat-messages {
                padding: 8px;
            }

            .message-content-wrapper {
                max-width: 90%;
            }

            .control-btn:nth-child(2),
            .control-btn:nth-child(3) {
                display: none;
            }
        }

        @media (max-width: 480px) {
            .logo {
                width: 36px;
                height: 36px;
                font-size: 20px;
            }

            .brand-title {
                font-size: 14px;
            }

            .lang-badge {
                font-size: 9px;
                padding: 2px 5px;
            }

            .demo-badge {
                display: none;
            }

            .separator {
                display: none;
            }

            .message-content-wrapper {
                max-width: 95%;
            }

            .message-bubble {
                padding: 8px 12px;
                font-size: 14px;
            }

            .welcome-message {
                font-size: 13px;
            }

            .welcome-message h2 {
                font-size: 1.2em;
            }

            .welcome-message h3 {
                font-size: 1.05em;
            }

            .suggestion-chip {
                font-size: 11px;
                padding: 5px 10px;
            }
        }"}, {"old_string": "                    <span class=\"powered-by\">Symplissime AI by <span class=\"starware-link\">STARWARE</span></span>\n                    <span class=\"separator\">\u2022</span>\n                    <a href=\"https://www.symplissime.fr\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"color: var(--crom-vert-medical); font-weight: 600; text-decoration: none; font-size: 13px;\">www.symplissime.fr</a>", "new_string": "                    <span class=\"powered-by\">Symplissime AI by <span class=\"starware-link\">STARWARE</span></span>\n                    <span class=\"separator\">\u2022</span>\n                    <a href=\"https://www.symplissime.fr\" target=\"_blank\" rel=\"noopener noreferrer\" style=\"color: #00994d; font-weight: 600; text-decoration: none; font-size: 13px;\">www.symplissime.fr</a>"}]
    </style>
</head>
<body>
    <div class="chat-container">
        <div class="chat-header">
            <div class="header-left">
                <div class="logo">⚕️</div>
                <div class="brand-info">
                    <span class="lang-badge">FR</span>
                    <span class="brand-title">CROM IA Assistant</span>
                    <span class="demo-badge">DÉMO</span>
                    <span class="separator">•</span>
                    <span class="brand-subtitle">Chat Code Déontologique</span>
                    <span class="separator">•</span>
                    <span class="powered-by">Symplissime AI by <span class="starware-link">STARWARE</span></span>
                    <span class="separator">•</span>
                    <a href="https://www.symplissime.fr" target="_blank" rel="noopener noreferrer" style="color: #00994d; font-weight: 600; text-decoration: none; font-size: 13px;">www.symplissime.fr</a>
                </div>
            </div>
            <div class="header-actions">
                <button class="control-btn" onclick="showConfig()" title="Configuration">⚙️</button>
                <button class="control-btn" onclick="exportHistory()" title="Exporter">💾</button>
                <button class="control-btn" onclick="clearHistory()" title="Effacer">🗑️</button>
            </div>
        </div>

        <div class="chat-messages" id="chatMessages">
            <div class="messages-container">
            <div class="message-wrapper assistant">
                <div class="message-content-wrapper">
                    <div class="message-bubble welcome-message">
                        <h2>👋 Bienvenue sur l'Assistant CROM</h2>

                        <p>Je suis votre <strong>assistant IA spécialisé en déontologie médicale</strong>, développé pour vous accompagner dans vos questions relatives au Code de déontologie médicale français.</p>

                        <h3>💡 Ce que je peux faire pour vous :</h3>
                        <ul>
                            <li><strong>Répondre à vos questions</strong> sur le Code de déontologie médicale</li>
                            <li><strong>Clarifier les articles</strong> et leurs applications pratiques</li>
                            <li><strong>Analyser des situations</strong> déontologiques complexes</li>
                            <li><strong>Vous guider</strong> sur les bonnes pratiques professionnelles</li>
                            <li><strong>Analyser des documents</strong> (PDF, images, textes) en lien avec la déontologie</li>
                        </ul>

                        <h3>⚡ Questions rapides (cliquez pour essayer) :</h3>
                        <div class="quick-suggestions">
                            <span class="suggestion-chip" onclick="askQuestion(&quot;Quelles sont les règles du secret médical ?&quot;)">🔒 Secret médical</span>
                            <span class="suggestion-chip" onclick="askQuestion(&quot;Comment gérer un conflit d'intérêts ?&quot;)">⚖️ Conflit d'intérêts</span>
                            <span class="suggestion-chip" onclick="askQuestion(&quot;Règles sur la publicité médicale&quot;)">📢 Publicité médicale</span>
                            <span class="suggestion-chip" onclick="askQuestion(&quot;Obligations en cas de maltraitance&quot;)">🛡️ Maltraitance</span>
                            <span class="suggestion-chip" onclick="askQuestion(&quot;Droits et devoirs du médecin&quot;)">📋 Droits & devoirs</span>
                        </div>

                        <p><em>N'hésitez pas à poser vos questions ou à joindre des documents pour analyse !</em></p>
                    </div>
                    <div class="message-time">⚕️ Maintenant</div>
                </div>
            </div>
            </div>
        </div>

        <div class="typing-indicator" id="typingIndicator">
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
            <div class="typing-dot"></div>
        </div>

        <div class="chat-input-container">
            <form class="input-form" id="chatForm">
                <button type="button" class="attach-btn" id="attachBtn" title="Joindre un fichier">📎</button>
                <input type="file" id="fileInput" style="display:none" accept="image/*,.pdf,.doc,.docx,.txt,.csv" multiple>
                <input
                    type="text"
                    class="message-input"
                    id="messageInput"
                    placeholder="🇫🇷 Posez votre question en français sur la déontologie médicale..."
                    autocomplete="off"
                    maxlength="5000"
                    required
                    lang="fr"
                >
                <button type="submit" class="send-button" id="sendButton">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M2 21l21-9L2 3v7l15 2-15 2v7z"/>
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        // ⚡ CROM Ultra Chat Engine
        class CROMChat {
            constructor() {
                this.messages = [];
                this.isProcessing = false;
                this.init();
            }

            init() {
                this.bindEvents();
                this.loadHistory();
            }

            bindEvents() {
                // Form submit
                document.getElementById('chatForm').addEventListener('submit', (e) => {
                    e.preventDefault();
                    this.sendMessage();
                });

                // File upload
                const attachBtn = document.getElementById('attachBtn');
                const fileInput = document.getElementById('fileInput');

                attachBtn.addEventListener('click', () => fileInput.click());

                fileInput.addEventListener('change', (e) => {
                    if (e.target.files.length > 0) {
                        this.handleFileUpload(e.target.files);
                        e.target.value = '';
                    }
                });

                // Keyboard shortcuts
                document.addEventListener('keydown', (e) => {
                    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });
            }

            sendMessage() {
                const input = document.getElementById('messageInput');
                const message = input.value.trim();

                if (!message || this.isProcessing) return;

                // Disable input
                this.setInputState(false);

                // Add user message
                this.addMessage(message, true);

                // Clear input
                input.value = '';

                // Show typing
                this.showTyping();

                // Send to API
                this.sendToAPI(message);
            }

            async sendToAPIWithDocument(message, documentId, filename) {
                // Show typing
                this.showTyping();
                this.setInputState(false);

                // Add user message about document analysis
                this.addMessage(`🔍 Analyse du document : ${filename}`, true);

                // Wait a bit for document indexing
                await new Promise(resolve => setTimeout(resolve, 2000));

                // Send the analysis request
                await this.sendToAPI(message);
            }

            async sendToAPI(message) {
                try {
                    const formData = new FormData();
                    formData.append('action', 'chat');
                    formData.append('message', message);
                    formData.append('workspace', 'crom');

                    const response = await fetch('', {
                        method: 'POST',
                        body: formData
                    });

                    const data = await response.json();

                    if (data.success) {
                        this.addMessage(data.message, false);
                    } else {
                        throw new Error(data.error || 'Erreur inconnue');
                    }

                } catch (error) {
                    console.error('API Error:', error);
                    this.showToast('Configuration requise - Voir le message', 'warning', 5000);

                    const errorMessage = [
                        '⚠️ **Configuration AnythingLLM Requise**',
                        '',
                        'Le workspace CROM sur le serveur AnythingLLM n\'a pas de modèle LLM configuré.',
                        '',
                        '**📋 Étapes de configuration (administrateur) :**',
                        '',
                        '**1. Connectez-vous à AnythingLLM**',
                        '   → http://llm.symplissime.fr:4004',
                        '   → User: crom / Pass: crom123456',
                        '',
                        '**2. Configurez un fournisseur LLM**',
                        '   → Cliquez sur ⚙️ Settings (menu de gauche)',
                        '   → Allez dans **LLM Preference**',
                        '   → Choisissez : OpenAI, Anthropic, Ollama, ou autre',
                        '   → Entrez votre API Key',
                        '   → Cliquez **Save**',
                        '',
                        '**3. Assignez le modèle au workspace CROM**',
                        '   → Retournez au Dashboard',
                        '   → Ouvrez le workspace **CROM**',
                        '   → Cliquez sur ⚙️ Settings (en haut)',
                        '   → Dans **Chat Settings**, sélectionnez votre modèle',
                        '   → Cliquez **Update Workspace**',
                        '',
                        '**4. Testez**',
                        '   → Envoyez un message test dans l\'interface AnythingLLM',
                        '   → Si ça fonctionne, revenez ici et réessayez',
                        '',
                        '---',
                        '',
                        '**💡 Besoin d\'aide ?**',
                        'Consultez le fichier CONFIGURATION_ANYTHINGLLM.md dans le dossier du projet.',
                        '',
                        '*Erreur technique : ' + error.message + '*'
                    ].join('\n');

                    this.addMessage(errorMessage, false);
                } finally {
                    this.hideTyping();
                    this.setInputState(true);
                }
            }

            async handleFileUpload(files) {
                for (const file of files) {
                    if (file.size > 10 * 1024 * 1024) {
                        this.showToast(`${file.name} trop volumineux (max 10MB)`, 'error');
                        continue;
                    }

                    this.setInputState(false);
                    this.showToast(`Téléversement de ${file.name}...`, 'info');

                    const formData = new FormData();
                    formData.append('action', 'upload');
                    formData.append('file', file);
                    formData.append('workspace', 'crom');

                    try {
                        const response = await fetch('', {
                            method: 'POST',
                            body: formData
                        });

                        const data = await response.json();

                        if (data.success) {
                            this.addMessage(`📄 Document joint : ${file.name} (${this.formatSize(file.size)})`, true);
                            this.showToast(`${file.name} téléversé avec succès`, 'success');

                            if (data.document_id) {
                                // Envoyer un message incluant le contexte du document
                                const docMessage = `J'ai uploadé le document "${file.name}". Peux-tu analyser son contenu et me donner un résumé en lien avec la déontologie médicale ? Utilise les informations de ce document pour répondre.`;
                                this.sendToAPIWithDocument(docMessage, data.document_id, file.name);
                            }
                        } else {
                            throw new Error(data.error);
                        }

                    } catch (error) {
                        this.showToast(`Échec: ${error.message}`, 'error');
                    } finally {
                        this.setInputState(true);
                    }
                }
            }

            addMessage(content, isUser = false) {
                const messagesDiv = document.getElementById('chatMessages');
                const container = messagesDiv.querySelector('.messages-container');

                const wrapper = document.createElement('div');
                wrapper.className = `message-wrapper ${isUser ? 'user' : 'assistant'}`;

                const contentWrapper = document.createElement('div');
                contentWrapper.className = 'message-content-wrapper';

                const bubble = document.createElement('div');
                bubble.className = 'message-bubble';
                bubble.innerHTML = this.processContent(content);

                const time = document.createElement('div');
                time.className = 'message-time';

                const icon = isUser ? '👤' : '⚕️';
                const timeText = new Date().toLocaleTimeString('fr-FR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
                time.innerHTML = `${icon} ${timeText}`;

                contentWrapper.appendChild(bubble);
                contentWrapper.appendChild(time);

                // Add actions for assistant messages
                if (!isUser) {
                    const actions = document.createElement('div');
                    actions.className = 'message-actions';
                    actions.innerHTML = `
                        <button class="action-icon" onclick="copyMessage(this)" title="Copier">📋</button>
                        <button class="action-icon" onclick="readAloud(this)" title="Lecture vocale">🔊</button>
                        <button class="action-icon" onclick="sendByEmail(this)" title="Envoyer par email">📧</button>
                        <button class="action-icon" onclick="voteUp(this)" title="Pouce haut">👍</button>
                        <button class="action-icon" onclick="voteDown(this)" title="Pouce bas">👎</button>
                    `;
                    contentWrapper.appendChild(actions);
                }

                wrapper.appendChild(contentWrapper);
                container.appendChild(wrapper);

                // Smooth scroll to bottom
                setTimeout(() => {
                    messagesDiv.scrollTo({
                        top: messagesDiv.scrollHeight,
                        behavior: 'smooth'
                    });
                }, 50);

                // Save message
                this.messages.push({ content, isUser, timestamp: new Date() });
                this.saveHistory();
            }

            processContent(content) {
                // Enhanced markdown processing with better formatting
                let processed = content
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;');

                // Headers
                processed = processed.replace(/^### (.*?)$/gm, '<h3>$1</h3>');
                processed = processed.replace(/^## (.*?)$/gm, '<h2>$1</h2>');
                processed = processed.replace(/^# (.*?)$/gm, '<h1>$1</h1>');

                // Lists
                processed = processed.replace(/^\* (.*?)$/gm, '<li>$1</li>');
                processed = processed.replace(/^- (.*?)$/gm, '<li>$1</li>');
                processed = processed.replace(/^(\d+)\. (.*?)$/gm, '<li>$2</li>');

                // Wrap consecutive <li> in <ul>
                processed = processed.replace(/(<li>.*?<\/li>\s*)+/g, '<ul>$&</ul>');

                // Bold and italic
                processed = processed.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                processed = processed.replace(/\*(.*?)\*/g, '<em>$1</em>');

                // Code blocks
                processed = processed.replace(/`([^`]+)`/g, '<code>$1</code>');

                // Line breaks (but not in lists)
                processed = processed.replace(/\n(?!<\/?(ul|li))/g, '<br>');

                // Links
                processed = processed.replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>');

                // Paragraphs
                processed = processed.split('<br><br>').map(p => {
                    if (!p.match(/^<(ul|h[1-3])/)) {
                        return `<p>${p}</p>`;
                    }
                    return p;
                }).join('');

                return processed;
            }

            setInputState(enabled) {
                const form = document.getElementById('chatForm');
                const input = document.getElementById('messageInput');
                const sendBtn = document.getElementById('sendButton');
                const attachBtn = document.getElementById('attachBtn');

                this.isProcessing = !enabled;

                if (enabled) {
                    form.classList.remove('disabled');
                    input.disabled = false;
                    input.placeholder = '🇫🇷 Posez votre question en français sur la déontologie médicale...';
                    sendBtn.disabled = false;
                    attachBtn.disabled = false;
                    input.focus();
                } else {
                    form.classList.add('disabled');
                    input.disabled = true;
                    input.placeholder = "⏳ L'assistant IA réfléchit en français...";
                    sendBtn.disabled = true;
                    attachBtn.disabled = true;
                }
            }

            showTyping() {
                document.getElementById('typingIndicator').classList.add('show');
            }

            hideTyping() {
                document.getElementById('typingIndicator').classList.remove('show');
            }

            showToast(message, type = 'info', duration = 3000) {
                const toast = document.getElementById('toast');
                toast.textContent = message;
                toast.className = `toast ${type} show`;

                setTimeout(() => {
                    toast.classList.remove('show');
                }, duration);
            }

            formatSize(bytes) {
                if (bytes === 0) return '0 B';
                const k = 1024;
                const sizes = ['B', 'KB', 'MB', 'GB'];
                const i = Math.floor(Math.log(bytes) / Math.log(k));
                return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
            }

            saveHistory() {
                localStorage.setItem('crom_messages', JSON.stringify(this.messages));
            }

            loadHistory() {
                const saved = localStorage.getItem('crom_messages');
                if (saved) {
                    try {
                        this.messages = JSON.parse(saved);
                        // Optionally restore messages to UI
                    } catch (e) {
                        console.error('Failed to load history');
                    }
                }
            }
        }

        // Global functions
        function copyMessage(btn) {
            const bubble = btn.closest('.message-content-wrapper').querySelector('.message-bubble');
            const text = bubble.innerText;

            navigator.clipboard.writeText(text).then(() => {
                window.cromChat.showToast('Message copié !', 'success', 2000);
                btn.textContent = '✓';
                setTimeout(() => btn.textContent = '📋', 1500);
            }).catch(() => {
                window.cromChat.showToast('Erreur de copie', 'error');
            });
        }

        function readAloud(btn) {
            const bubble = btn.closest('.message-content-wrapper').querySelector('.message-bubble');
            const text = bubble.innerText;

            if ('speechSynthesis' in window) {
                window.speechSynthesis.cancel();

                const utterance = new SpeechSynthesisUtterance(text);
                utterance.lang = 'fr-FR';
                utterance.rate = 0.9;
                utterance.pitch = 1;

                btn.textContent = '⏸️';
                utterance.onend = () => {
                    btn.textContent = '🔊';
                };

                window.speechSynthesis.speak(utterance);
                window.cromChat.showToast('Lecture en cours...', 'info', 2000);
            } else {
                window.cromChat.showToast('Lecture vocale non disponible', 'error');
            }
        }

        function sendByEmail(btn) {
            const bubble = btn.closest('.message-content-wrapper').querySelector('.message-bubble');
            const text = bubble.innerText;

            const subject = encodeURIComponent('Réponse CROM - Code Déontologie');
            const body = encodeURIComponent('Bonjour,\n\nVoici la réponse de l\'assistant CROM :\n\n' + text + '\n\n---\nGénéré par CROM IA Assistant - Symplissime AI');

            window.location.href = `mailto:?subject=${subject}&body=${body}`;
            window.cromChat.showToast('Client email ouvert', 'success', 2000);
        }

        function voteUp(btn) {
            const bubble = btn.closest('.message-content-wrapper').querySelector('.message-bubble');
            const text = bubble.innerText;

            btn.classList.add('voted');
            btn.textContent = '✓';

            // Save feedback
            const formData = new FormData();
            formData.append('action', 'feedback');
            formData.append('question', 'Vote positif');
            formData.append('answer', text);

            fetch('', { method: 'POST', body: formData });

            window.cromChat.showToast('Merci pour votre retour positif !', 'success', 2000);

            setTimeout(() => {
                btn.classList.remove('voted');
                btn.textContent = '👍';
            }, 2000);
        }

        function voteDown(btn) {
            const bubble = btn.closest('.message-content-wrapper').querySelector('.message-bubble');
            const text = bubble.innerText;

            btn.classList.add('voted');
            btn.textContent = '✓';

            // Save feedback
            const formData = new FormData();
            formData.append('action', 'feedback');
            formData.append('question', 'Vote négatif');
            formData.append('answer', text);

            fetch('', { method: 'POST', body: formData });

            window.cromChat.showToast('Merci pour votre retour, nous allons nous améliorer', 'info', 3000);

            setTimeout(() => {
                btn.classList.remove('voted');
                btn.textContent = '👎';
            }, 2000);
        }

        function askQuestion(question) {
            const input = document.getElementById('messageInput');
            input.value = question;
            input.focus();

            // Auto-submit after a short delay
            setTimeout(() => {
                document.getElementById('chatForm').dispatchEvent(new Event('submit'));
            }, 300);
        }

        function showConfig() {
            const config = [
                { label: 'Endpoint', value: '<?php echo $BASE_URL; ?>' },
                { label: 'Workspace', value: '<?php echo $DEFAULT_WORKSPACE; ?>' },
                { label: 'Utilisateur', value: '<?php echo $CURRENT_USER; ?>' },
                { label: 'Version', value: 'CROM Ultra 2.0' },
                { label: 'Status', value: 'Connecté à Symplissime AI' }
            ];

            let info = '⚙️ Configuration actuelle\n\n';
            config.forEach(item => {
                info += item.label + ': ' + item.value + '\n';
            });

            alert(info);
        }

        function exportHistory() {
            const chat = window.cromChat;
            const data = JSON.stringify(chat.messages, null, 2);
            const blob = new Blob([data], { type: 'application/json' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `crom_chat_${new Date().toISOString().slice(0,10)}.json`;
            a.click();
            URL.revokeObjectURL(url);
            chat.showToast('Historique exporté', 'success');
        }

        function clearHistory() {
            if (confirm('Êtes-vous sûr de vouloir effacer l\'historique ?')) {
                const messagesDiv = document.getElementById('chatMessages');
                messagesDiv.innerHTML = `
                    <div class="messages-container">
                    <div class="message-wrapper assistant">
                        <div class="message-content-wrapper">
                            <div class="message-bubble welcome-message">
                                <h2>👋 Bienvenue sur l'Assistant CROM</h2>

                                <p>Je suis votre <strong>assistant IA spécialisé en déontologie médicale</strong>, développé pour vous accompagner dans vos questions relatives au Code de déontologie médicale français.</p>

                                <h3>💡 Ce que je peux faire pour vous :</h3>
                                <ul>
                                    <li><strong>Répondre à vos questions</strong> sur le Code de déontologie médicale</li>
                                    <li><strong>Clarifier les articles</strong> et leurs applications pratiques</li>
                                    <li><strong>Analyser des situations</strong> déontologiques complexes</li>
                                    <li><strong>Vous guider</strong> sur les bonnes pratiques professionnelles</li>
                                    <li><strong>Analyser des documents</strong> (PDF, images, textes) en lien avec la déontologie</li>
                                </ul>

                                <h3>⚡ Questions rapides (cliquez pour essayer) :</h3>
                                <div class="quick-suggestions">
                                    <span class="suggestion-chip" onclick="askQuestion(&quot;Quelles sont les règles du secret médical ?&quot;)">🔒 Secret médical</span>
                                    <span class="suggestion-chip" onclick="askQuestion(&quot;Comment gérer un conflit d'intérêts ?&quot;)">⚖️ Conflit d'intérêts</span>
                                    <span class="suggestion-chip" onclick="askQuestion(&quot;Règles sur la publicité médicale&quot;)">📢 Publicité médicale</span>
                                    <span class="suggestion-chip" onclick="askQuestion(&quot;Obligations en cas de maltraitance&quot;)">🛡️ Maltraitance</span>
                                    <span class="suggestion-chip" onclick="askQuestion(&quot;Droits et devoirs du médecin&quot;)">📋 Droits & devoirs</span>
                                </div>

                                <p><em>N'hésitez pas à poser vos questions ou à joindre des documents pour analyse !</em></p>
                            </div>
                            <div class="message-time">⚕️ Maintenant</div>
                        </div>
                    </div>
                    </div>
                `;
                window.cromChat.messages = [];
                window.cromChat.saveHistory();
                window.cromChat.showToast('Historique effacé', 'success');
            }
        }

        // Initialize
        window.cromChat = new CROMChat();
    </script>
</body>
</html>