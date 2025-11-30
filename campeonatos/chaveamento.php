<?php
require_once "../classes/Database.php";
session_start();

$pdo = Database::getConnection();

if (!isset($_GET["id"])) {
    die("Campeonato não especificado.");
}
$campeonato_id = (int) $_GET["id"];

// Busca confrontos
$stmt = $pdo->prepare(
    "SELECT * FROM confrontos WHERE campeonato_id = ? ORDER BY fase, rodada, id"
);
$stmt->execute([$campeonato_id]);
$confrontos = $stmt->fetchAll();

// Organiza confrontos por fase e rodada
$organizado = [];
foreach ($confrontos as $c) {
    if (!isset($organizado[$c["fase"]])) {
        $organizado[$c["fase"]] = [];
    }
    if (!isset($organizado[$c["fase"]][$c["rodada"]])) {
        $organizado[$c["fase"]][$c["rodada"]] = [];
    }
    $organizado[$c["fase"]][$c["rodada"]][] = $c;
}

function nome_time($pdo, $id) {
    if (!$id || $id == 0) {
        return "(W.O.)";
    }

    $stmt = $pdo->prepare(
        "SELECT nome, horario_preferencia FROM times WHERE id = ?"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return "Time $id";
    }

    $mostrarHorario = isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0;
    return $mostrarHorario
        ? $row["nome"] . " (" . $row["horario_preferencia"] . ")"
        : $row["nome"];
}

include "../includes/header.php";
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chaveamento do Campeonato</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
            --accent-blue: #3b82f6;
            --accent-teal: #14b8a6;
            --accent-amber: #f59e0b;
            --accent-green: #10b981;
            --accent-red: #ef4444;
            --border-radius: 12px;
            --box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        body {
            background: linear-gradient(135deg, #f0f2f5 0%, #e4e7eb 100%);
            color: var(--gray-800);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            line-height: 1.6;
            min-height: 100vh;
            padding-bottom: 40px;
        }

        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 20px;
        }

        .page-header-container {
            background: linear-gradient(120deg, var(--gray-800), var(--gray-900));
            color: white;
            padding: 3rem 0 2.5rem;
            margin: 2.5rem auto;
            position: relative;
            overflow: hidden;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }

        .page-header-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at top right, rgba(59, 130, 246, 0.1) 0%, transparent 40%);
            pointer-events: none;
        }

        .page-title {
            text-align: center;
            position: relative;
            padding-bottom: 25px;
            margin-bottom: 25px;
        }

        .page-title h1 {
            font-weight: 700;
            font-size: 2.5rem;
            letter-spacing: -0.025em;
            margin-bottom: 0.5rem;
            color: white;
        }

        .page-title::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 80px;
            height: 4px;
            background: linear-gradient(to right, var(--accent-blue), var(--accent-teal));
            border-radius: 2px;
        }

        .main-content {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 30px;
            margin-bottom: 40px;
        }

        /* Brackets Styles */
        .bracket-section {
            margin-top: 40px;
            border: 2px solid var(--gray-200);
            border-radius: var(--border-radius);
            padding: 25px;
            background: var(--gray-50);
        }

        .winners-bracket {
            border-color: var(--accent-blue);
            background: rgba(59, 130, 246, 0.05);
        }

        .losers-bracket {
            border-color: var(--accent-amber);
            background: rgba(245, 158, 11, 0.05);
        }

        .final-bracket {
            border-color: var(--accent-teal);
            background: rgba(20, 184, 166, 0.05);
        }

        .bracket-title {
            font-weight: 700;
            margin-bottom: 25px;
            font-size: 1.6rem;
            text-align: center;
            position: relative;
            padding-bottom: 15px;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .winners-bracket .bracket-title {
            color: var(--accent-blue);
        }

        .losers-bracket .bracket-title {
            color: var(--accent-amber);
        }

        .final-bracket .bracket-title {
            color: var(--accent-teal);
        }

        .bracket-title::after {
            content: "";
            position: absolute;
            bottom: 0;
            left: 50%;
            transform: translateX(-50%);
            width: 60px;
            height: 3px;
            border-radius: 2px;
        }

        .winners-bracket .bracket-title::after {
            background: var(--accent-blue);
        }

        .losers-bracket .bracket-title::after {
            background: var(--accent-amber);
        }

        .final-bracket .bracket-title::after {
            background: var(--accent-teal);
        }

        .fase-container {
            display: flex;
            flex-wrap: nowrap;
            overflow-x: auto;
            gap: 30px;
            padding: 20px 10px 30px;
            margin-bottom: 30px;
        }

        .fase {
            min-width: 320px;
            background: white;
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            border: 1px solid var(--gray-200);
        }

        .fase-header {
            padding-bottom: 15px;
            margin-bottom: 20px;
            border-bottom: 2px solid var(--gray-200);
        }

        .fase-titulo {
            font-weight: 600;
            font-size: 1.3rem;
            color: var(--gray-700);
            text-transform: uppercase;
            letter-spacing: 1px;
            text-align: center;
        }

        .winners-bracket .fase-titulo {
            color: var(--accent-blue);
        }

        .losers-bracket .fase-titulo {
            color: var(--accent-amber);
        }

        .final-bracket .fase-titulo {
            color: var(--accent-teal);
        }

        .confronto {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 20px;
            margin-bottom: 20px;
            transition: var(--transition);
            border: 1px solid var(--gray-200);
            position: relative;
        }

        .confronto:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px -10px rgba(0, 0, 0, 0.1);
        }

        .winners-bracket .confronto {
            border-left: 4px solid var(--accent-blue);
        }

        .losers-bracket .confronto {
            border-left: 4px solid var(--accent-amber);
        }

        .final-bracket .confronto {
            border-left: 4px solid var(--accent-teal);
        }

        .match-info {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .team-option {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: var(--transition);
            border: 1px solid var(--gray-200);
            position: relative;
        }

        .team-option:hover {
            background: var(--gray-50);
        }

        .team-option.selected {
            background: rgba(59, 130, 246, 0.1);
            border-color: var(--accent-blue);
        }

        .team-option.winner {
            background: rgba(16, 185, 129, 0.1);
            border-color: var(--accent-green);
        }

        .team-option input[type="radio"] {
            margin-right: 12px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }

        .team-option label {
            flex: 1;
            cursor: pointer;
            font-weight: 500;
            color: var(--gray-700);
        }

        .team-option.winner label {
            color: var(--accent-green);
            font-weight: 600;
        }

        .vs-separator {
            text-align: center;
            margin: 8px 0;
            font-weight: 600;
            color: var(--gray-500);
            position: relative;
        }

        .vs-separator::before,
        .vs-separator::after {
            content: "";
            position: absolute;
            top: 50%;
            width: 40%;
            height: 1px;
            background: var(--gray-300);
        }

        .vs-separator::before {
            left: 0;
        }

        .vs-separator::after {
            right: 0;
        }

        .form-actions {
            text-align: center;
            margin-top: 40px;
            padding-top: 30px;
            border-top: 1px solid var(--gray-200);
        }

        .btn-save {
            padding: 14px 40px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 1.1rem;
            background: var(--accent-teal);
            color: white;
        }

        .btn-save:hover {
            background: #0d9488;
            box-shadow: 0 4px 12px rgba(20, 184, 166, 0.3);
            transform: translateY(-3px);
        }

        .btn-save:disabled {
            background: var(--gray-400);
            cursor: not-allowed;
            transform: none;
        }

        .no-brackets {
            text-align: center;
            padding: 50px 20px;
        }

        .no-brackets i {
            font-size: 4rem;
            color: var(--gray-300);
            margin-bottom: 20px;
            opacity: 0.7;
        }

        .no-brackets h3 {
            font-weight: 700;
            color: var(--gray-700);
            margin-bottom: 15px;
            font-size: 1.5rem;
        }

        .no-brackets p {
            color: var(--gray-600);
            max-width: 500px;
            margin: 0 auto;
        }

        /* Status badges */
        .match-status {
            position: absolute;
            top: -10px;
            right: 10px;
            background: var(--accent-green);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .match-status.pending {
            background: var(--accent-amber);
        }

        .match-status.bye {
            background: var(--accent-blue);
        }

        /* Scrollbar personalizada */
        .fase-container::-webkit-scrollbar {
            height: 8px;
        }

        .fase-container::-webkit-scrollbar-track {
            background: var(--gray-100);
            border-radius: 4px;
        }

        .fase-container::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 4px;
        }

        .fase-container::-webkit-scrollbar-thumb:hover {
            background: var(--gray-500);
        }

        /* Toast notifications */
        .toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 25px;
            border-radius: var(--border-radius);
            color: white;
            font-weight: bold;
            box-shadow: var(--box-shadow);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 10px;
            opacity: 0;
            transform: translateY(-20px);
            transition: all 0.3s ease;
        }
        
        .toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        
        .toast.success {
            background-color: var(--accent-green);
        }
        
        .toast.error {
            background-color: var(--accent-red);
        }

        @media (max-width: 768px) {
            .fase {
                min-width: 280px;
            }
            
            .page-title h1 {
                font-size: 2.2rem;
            }
            
            .page-header-container {
                margin: 1.5rem auto;
            }
            
            .bracket-section {
                padding: 15px;
            }
            
            .fase-container {
                gap: 20px;
                padding: 15px 5px 20px;
            }
        }

        @media (max-width: 480px) {
            .fase {
                min-width: 260px;
            }
            
            .page-title h1 {
                font-size: 1.8rem;
            }
            
            .main-content {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="page-header-container">
            <div class="page-title">
                <h1>Chaveamento do Campeonato</h1>
            </div>
        </div>
    </div>

    <div class="page-container">
        <div class="main-content">
            <?php if (!empty($organizado)): ?>
                <?php if (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0): ?>
                    <form id="chaveamento-form" action="salvar_vencedores.php" method="post">
                        <input type="hidden" name="campeonato_id" value="<?= $campeonato_id ?>">
                <?php endif; ?>
                
                <!-- Winners Bracket -->
                <?php if (!empty($organizado['winners'])): ?>
                    <div class="bracket-section winners-bracket">
                        <h3 class="bracket-title">
                            <i class="fas fa-trophy"></i> Chave Principal (Winners)
                        </h3>
                        
                        <div class="fase-container">
                            <?php 
                            // Ordenar rodadas numericamente
                            ksort($organizado['winners']);
                            foreach ($organizado['winners'] as $rodada => $confrontos): ?>
                                <div class="fase">
                                    <div class="fase-header">
                                        <h4 class="fase-titulo">Rodada <?= $rodada ?></h4>
                                    </div>
                                    
                                    <?php foreach ($confrontos as $c): ?>
                                        <div class="confronto">
                                            <?php if ($c["vencedor"]): ?>
                                                <div class="match-status">Finalizado</div>
                                            <?php elseif (!$c["time1"] || !$c["time2"]): ?>
                                                <div class="match-status bye">W.O.</div>
                                            <?php else: ?>
                                                <div class="match-status pending">Pendente</div>
                                            <?php endif; ?>
                                            
                                            <div class="match-info">
                                                <div class="team-option <?= $c["vencedor"] == $c["time1"] ? 'winner' : '' ?> <?= $c["vencedor"] == $c["time1"] ? "selected" : "" ?>">
                                                    <input 
                                                        type="radio" 
                                                        name="vencedor[<?= $c["id"] ?>]" 
                                                        value="<?= $c["time1"] ?>" 
                                                        <?= $c["vencedor"] == $c["time1"] ? "checked" : "" ?> 
                                                        <?= (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0) ? "" : "disabled" ?>
                                                        <?= (!$c["time1"] || $c["time1"] == 0) ? "disabled" : "" ?>
                                                        data-confronto="<?= $c["id"] ?>"
                                                    >
                                                    <label><?= nome_time($pdo, $c["time1"]) ?></label>
                                                </div>
                                                
                                                <div class="vs-separator">VS</div>
                                                
                                                <div class="team-option <?= $c["vencedor"] == $c["time2"] ? 'winner' : '' ?> <?= $c["vencedor"] == $c["time2"] ? "selected" : "" ?>">
                                                    <input 
                                                        type="radio" 
                                                        name="vencedor[<?= $c["id"] ?>]" 
                                                        value="<?= $c["time2"] ?>" 
                                                        <?= $c["vencedor"] == $c["time2"] ? "checked" : "" ?> 
                                                        <?= (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0) ? "" : "disabled" ?>
                                                        <?= (!$c["time2"] || $c["time2"] == 0) ? "disabled" : "" ?>
                                                        data-confronto="<?= $c["id"] ?>"
                                                    >
                                                    <label><?= nome_time($pdo, $c["time2"]) ?></label>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Losers Bracket -->
                <?php if (!empty($organizado['losers'])): ?>
                    <div class="bracket-section losers-bracket">
                        <h3 class="bracket-title">
                            <i class="fas fa-redo-alt"></i> Chave de Repescagem (Losers)
                        </h3>
                        
                        <div class="fase-container">
                            <?php 
                            ksort($organizado['losers']);
                            foreach ($organizado['losers'] as $rodada => $confrontos): ?>
                                <div class="fase">
                                    <div class="fase-header">
                                        <h4 class="fase-titulo">Rodada <?= $rodada ?></h4>
                                    </div>
                                    
                                    <?php foreach ($confrontos as $c): ?>
                                        <div class="confronto">
                                            <?php if ($c["vencedor"]): ?>
                                                <div class="match-status">Finalizado</div>
                                            <?php elseif (!$c["time1"] || !$c["time2"]): ?>
                                                <div class="match-status bye">W.O.</div>
                                            <?php else: ?>
                                                <div class="match-status pending">Pendente</div>
                                            <?php endif; ?>
                                            
                                            <div class="match-info">
                                                <div class="team-option <?= $c["vencedor"] == $c["time1"] ? 'winner' : '' ?> <?= $c["vencedor"] == $c["time1"] ? "selected" : "" ?>">
                                                    <input 
                                                        type="radio" 
                                                        name="vencedor[<?= $c["id"] ?>]" 
                                                        value="<?= $c["time1"] ?>" 
                                                        <?= $c["vencedor"] == $c["time1"] ? "checked" : "" ?> 
                                                        <?= (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0) ? "" : "disabled" ?>
                                                        <?= (!$c["time1"] || $c["time1"] == 0) ? "disabled" : "" ?>
                                                        data-confronto="<?= $c["id"] ?>"
                                                    >
                                                    <label><?= nome_time($pdo, $c["time1"]) ?></label>
                                                </div>
                                                
                                                <div class="vs-separator">VS</div>
                                                
                                                <div class="team-option <?= $c["vencedor"] == $c["time2"] ? 'winner' : '' ?> <?= $c["vencedor"] == $c["time2"] ? "selected" : "" ?>">
                                                    <input 
                                                        type="radio" 
                                                        name="vencedor[<?= $c["id"] ?>]" 
                                                        value="<?= $c["time2"] ?>" 
                                                        <?= $c["vencedor"] == $c["time2"] ? "checked" : "" ?> 
                                                        <?= (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0) ? "" : "disabled" ?>
                                                        <?= (!$c["time2"] || $c["time2"] == 0) ? "disabled" : "" ?>
                                                        data-confronto="<?= $c["id"] ?>"
                                                    >
                                                    <label><?= nome_time($pdo, $c["time2"]) ?></label>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Finais -->
                <?php 
                $fases_finais = ['final', 'grande_final'];
                foreach ($fases_finais as $fase_final): ?>
                    <?php if (!empty($organizado[$fase_final])): ?>
                        <div class="bracket-section final-bracket">
                            <h3 class="bracket-title">
                                <i class="fas fa-medal"></i> 
                                <?= $fase_final === 'final' ? 'Final do Campeonato' : 'Grande Final' ?>
                            </h3>
                            
                            <div class="fase-container">
                                <?php 
                                ksort($organizado[$fase_final]);
                                foreach ($organizado[$fase_final] as $rodada => $confrontos): ?>
                                    <div class="fase">
                                        <div class="fase-header">
                                            <h4 class="fase-titulo">
                                                <?= $fase_final === 'final' ? 'Partida Final' : 'Grande Final' ?>
                                            </h4>
                                        </div>
                                        
                                        <?php foreach ($confrontos as $c): ?>
                                            <div class="confronto">
                                                <?php if ($c["vencedor"]): ?>
                                                    <div class="match-status">Campeão</div>
                                                <?php else: ?>
                                                    <div class="match-status pending">Decisão</div>
                                                <?php endif; ?>
                                                
                                                <div class="match-info">
                                                    <div class="team-option <?= $c["vencedor"] == $c["time1"] ? 'winner' : '' ?> <?= $c["vencedor"] == $c["time1"] ? "selected" : "" ?>">
                                                        <input 
                                                            type="radio" 
                                                            name="vencedor[<?= $c["id"] ?>]" 
                                                            value="<?= $c["time1"] ?>" 
                                                            <?= $c["vencedor"] == $c["time1"] ? "checked" : "" ?> 
                                                            <?= (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0) ? "" : "disabled" ?>
                                                            <?= (!$c["time1"] || $c["time1"] == 0) ? "disabled" : "" ?>
                                                            data-confronto="<?= $c["id"] ?>"
                                                        >
                                                        <label><?= nome_time($pdo, $c["time1"]) ?></label>
                                                    </div>
                                                    
                                                    <div class="vs-separator">VS</div>
                                                    
                                                    <div class="team-option <?= $c["vencedor"] == $c["time2"] ? 'winner' : '' ?> <?= $c["vencedor"] == $c["time2"] ? "selected" : "" ?>">
                                                        <input 
                                                            type="radio" 
                                                            name="vencedor[<?= $c["id"] ?>]" 
                                                            value="<?= $c["time2"] ?>" 
                                                            <?= $c["vencedor"] == $c["time2"] ? "checked" : "" ?> 
                                                            <?= (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0) ? "" : "disabled" ?>
                                                            <?= (!$c["time2"] || $c["time2"] == 0) ? "disabled" : "" ?>
                                                            data-confronto="<?= $c["id"] ?>"
                                                        >
                                                        <label><?= nome_time($pdo, $c["time2"]) ?></label>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <?php if (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0): ?>
                    <div class="form-actions">
                        <button type="submit" class="btn-save" id="btn-salvar">
                            <i class="fas fa-save"></i> Salvar Vencedores
                        </button>
                    </div>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-brackets">
                    <i class="fas fa-sitemap"></i>
                    <h3>Chaveamento não disponível</h3>
                    <p>O chaveamento deste campeonato ainda não foi gerado ou está indisponível no momento.</p>
                    <?php if (isset($_SESSION["usuario"]) && $_SESSION["usuario"]["tipo"] != 0): ?>
                        <a href="editar.php?id=<?= $campeonato_id ?>" class="btn-save" style="margin-top: 20px; text-decoration: none;">
                            <i class="fas fa-cog"></i> Gerar Chaveamento
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Função para mostrar toast
        function showToast(type, message) {
            // Criar elemento de toast
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            // Adicionar ícone
            const icon = document.createElement('i');
            icon.className = `fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}`;
            toast.appendChild(icon);
            
            // Adicionar mensagem
            const text = document.createTextNode(message);
            toast.appendChild(text);
            
            // Adicionar ao corpo do documento
            document.body.appendChild(toast);
            
            // Mostrar toast
            setTimeout(() => {
                toast.classList.add('show');
            }, 10);
            
            // Remover após 3 segundos
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 3000);
        }

        // Validação do formulário
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('chaveamento-form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    const radioButtons = form.querySelectorAll('input[type="radio"]:checked');
                    if (radioButtons.length === 0) {
                        e.preventDefault();
                        showToast('error', 'Selecione pelo menos um vencedor para salvar!');
                        return;
                    }
                    
                    // Mostrar loading no botão
                    const btnSalvar = document.getElementById('btn-salvar');
                    const originalText = btnSalvar.innerHTML;
                    btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
                    btnSalvar.disabled = true;
                    
                    // O formulário será enviado normalmente
                });
            }
            
            // Adicionar interação aos radio buttons
            const radioInputs = document.querySelectorAll('input[type="radio"]');
            radioInputs.forEach(radio => {
                radio.addEventListener('change', function() {
                    const confrontoId = this.getAttribute('data-confronto');
                    const teamOptions = document.querySelectorAll(`input[data-confronto="${confrontoId}"]`);
                    
                    // Remover classe selected de todas as opções deste confronto
                    teamOptions.forEach(input => {
                        input.closest('.team-option').classList.remove('selected');
                    });
                    
                    // Adicionar classe selected à opção selecionada
                    if (this.checked) {
                        this.closest('.team-option').classList.add('selected');
                    }
                });
            });
            
            // Verificar se há toast da sessão
            <?php if (isset($_SESSION['toast'])): ?>
                showToast('<?= $_SESSION['toast']['type'] ?>', '<?= $_SESSION['toast']['message'] ?>');
                <?php unset($_SESSION['toast']); ?>
            <?php endif; ?>
        });
    </script>
</body>
</html>

<?php include "../includes/footer.php"; ?>