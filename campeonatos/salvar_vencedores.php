<?php
require_once "../classes/Database.php";
session_start();

$pdo = Database::getConnection();

if (!isset($_SESSION["usuario"]) || $_SESSION["usuario"]["tipo"] == 0) {
    die("Acesso negado.");
}

if (!isset($_POST["vencedor"], $_POST["campeonato_id"])) {
    die("Dados incompletos.");
}

$campeonato_id = (int) $_POST["campeonato_id"];
$vencedores = $_POST["vencedor"];

try {
    $pdo->beginTransaction();

    // Limpar e validar dados antes de processar
    limparDadosCorrompidos($pdo, $campeonato_id);
    validarEstadoCampeonato($pdo, $campeonato_id);

    foreach ($vencedores as $confronto_id => $vencedor_id) {
        $confronto_id = (int) $confronto_id;
        $vencedor_id = (int) $vencedor_id;

        $stmt = $pdo->prepare("SELECT * FROM confrontos WHERE id = ? AND campeonato_id = ?");
        $stmt->execute([$confronto_id, $campeonato_id]);
        $confronto = $stmt->fetch();

        if (!$confronto) continue;
        
        if ($vencedor_id != $confronto["time1"] && $vencedor_id != $confronto["time2"]) {
            continue;
        }

        $stmt = $pdo->prepare("UPDATE confrontos SET vencedor = ? WHERE id = ?");
        $stmt->execute([$vencedor_id, $confronto_id]);
        
        // CORREÇÃO: Lógica de eliminação/perda melhorada
        $perdedor_id = ($vencedor_id == $confronto["time1"]) ? $confronto["time2"] : $confronto["time1"];
        
        if ($perdedor_id && $perdedor_id > 0) {
            // Se for fase 'losers', o perdedor é ELIMINADO DEFINITIVAMENTE
            if ($confronto["fase"] === "losers") {
                marcarTimeComoEliminado($pdo, $campeonato_id, $perdedor_id);
                
                // Remover o perdedor de qualquer confronto futuro no losers bracket
                removerTimeDeConfrontosFuturos($pdo, $campeonato_id, $perdedor_id);
            }
            // Se for fase 'winners', o perdedor vai para o losers bracket
            else if ($confronto["fase"] === "winners") {
                if (!timeEstaNoLosersBracket($pdo, $campeonato_id, $perdedor_id) && 
                    !timeEstaEliminado($pdo, $campeonato_id, $perdedor_id)) {
                    adicionarPerdedorLosers($pdo, $campeonato_id, $perdedor_id, $confronto["rodada"]);
                }
            }
            // Se for fase 'final' ou 'grande_final', o perdedor é eliminado
            else if (in_array($confronto["fase"], ['final', 'grande_final'])) {
                marcarTimeComoEliminado($pdo, $campeonato_id, $perdedor_id);
            }
        }
    }

    gerarProximaRodadaWinners($pdo, $campeonato_id);
    gerarProximaRodadaLosers($pdo, $campeonato_id);
    verificarEFinal($pdo, $campeonato_id);
    repararRodadasLosers($pdo, $campeonato_id);

    $pdo->commit();
    
    $_SESSION['toast'] = [
        'type' => 'success',
        'message' => 'Vencedores salvos com sucesso!'
    ];

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['toast'] = [
        'type' => 'error',
        'message' => 'Erro ao salvar vencedores: ' . $e->getMessage()
    ];
}

header("Location: chaveamento.php?id=" . $campeonato_id);
exit();

// FUNÇÕES AUXILIARES

function limparDadosCorrompidos($pdo, $campeonato_id) {
    // Remover confrontos com rodadas negativas
    $stmt = $pdo->prepare("DELETE FROM confrontos WHERE campeonato_id = ? AND rodada < 0");
    $stmt->execute([$campeonato_id]);
    
    // Remover confrontos duplicados no losers bracket
    $stmt = $pdo->prepare("
        DELETE c1 FROM confrontos c1
        INNER JOIN confrontos c2 
        WHERE c1.id < c2.id 
        AND c1.campeonato_id = ? 
        AND c2.campeonato_id = ?
        AND c1.fase = c2.fase 
        AND c1.rodada = c2.rodada 
        AND c1.time1 = c2.time1 
        AND ((c1.time2 IS NULL AND c2.time2 IS NULL) OR c1.time2 = c2.time2)
    ");
    $stmt->execute([$campeonato_id, $campeonato_id]);
}

function validarEstadoCampeonato($pdo, $campeonato_id) {
    // Verificar times com múltiplas entradas no losers bracket
    $stmt = $pdo->prepare("
        SELECT time1 as time_id, COUNT(*) as count 
        FROM confrontos 
        WHERE campeonato_id = ? AND fase = 'losers' AND time2 IS NULL 
        AND vencedor IS NULL AND rodada > 0
        GROUP BY time1 
        HAVING count > 1
    ");
    $stmt->execute([$campeonato_id]);
    $duplicados = $stmt->fetchAll();
    
    foreach ($duplicados as $duplicado) {
        // Manter apenas o primeiro, remover os demais
        $stmt = $pdo->prepare("
            DELETE FROM confrontos 
            WHERE campeonato_id = ? AND fase = 'losers' 
            AND time1 = ? AND time2 IS NULL 
            AND vencedor IS NULL AND rodada > 0
            AND id > (
                SELECT MIN(id) FROM confrontos 
                WHERE campeonato_id = ? AND fase = 'losers' 
                AND time1 = ? AND time2 IS NULL 
                AND vencedor IS NULL AND rodada > 0
            )
        ");
        $stmt->execute([$campeonato_id, $duplicado['time_id'], $campeonato_id, $duplicado['time_id']]);
    }
}

function marcarTimeComoEliminado($pdo, $campeonato_id, $time_id) {
    // Primeiro, verificar se o time já está eliminado
    if (timeEstaEliminado($pdo, $campeonato_id, $time_id)) {
        return;
    }
    
    // Remover o time de qualquer confronto pendente no losers bracket
    removerTimeDeConfrontosFuturos($pdo, $campeonato_id, $time_id);
    
    // Marcar como eliminado
    $stmt = $pdo->prepare(
        "INSERT INTO confrontos (campeonato_id, time1, fase, rodada) 
         VALUES (?, ?, 'eliminado', -10000)"
    );
    $stmt->execute([$campeonato_id, $time_id]);
}

function removerTimeDeConfrontosFuturos($pdo, $campeonato_id, $time_id) {
    // Remover de confrontos pendentes no losers bracket
    $stmt = $pdo->prepare("
        DELETE FROM confrontos 
        WHERE campeonato_id = ? AND fase = 'losers' 
        AND vencedor IS NULL 
        AND (time1 = ? OR time2 = ?)
    ");
    $stmt->execute([$campeonato_id, $time_id, $time_id]);
}

function timeEstaEliminado($pdo, $campeonato_id, $time_id) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'eliminado' AND time1 = ?"
    );
    $stmt->execute([$campeonato_id, $time_id]);
    return $stmt->fetchColumn() > 0;
}

function timeEstaNoLosersBracket($pdo, $campeonato_id, $time_id) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'losers' 
         AND (time1 = ? OR time2 = ?) AND vencedor IS NULL"
    );
    $stmt->execute([$campeonato_id, $time_id, $time_id]);
    return $stmt->fetchColumn() > 0;
}

function adicionarPerdedorLosers($pdo, $campeonato_id, $perdedor_id, $rodada_winners) {
    if (timeEstaEliminado($pdo, $campeonato_id, $perdedor_id)) {
        return;
    }
    
    if (timeEstaNoLosersBracket($pdo, $campeonato_id, $perdedor_id)) {
        return;
    }
    
    $rodada_losers = $rodada_winners;
    
    $stmt = $pdo->prepare(
        "SELECT id, time1 FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'losers' 
         AND rodada = ? AND time2 IS NULL AND vencedor IS NULL
         ORDER BY id LIMIT 1"
    );
    $stmt->execute([$campeonato_id, $rodada_losers]);
    $confronto_existente = $stmt->fetch();
    
    if ($confronto_existente) {
        $stmt = $pdo->prepare("UPDATE confrontos SET time2 = ? WHERE id = ?");
        $stmt->execute([$perdedor_id, $confronto_existente["id"]]);
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO confrontos (campeonato_id, time1, fase, rodada) 
             VALUES (?, ?, 'losers', ?)"
        );
        $stmt->execute([$campeonato_id, $perdedor_id, $rodada_losers]);
    }
}

function gerarProximaRodadaWinners($pdo, $campeonato_id) {
    $stmt = $pdo->prepare(
        "SELECT MAX(rodada) FROM confrontos WHERE campeonato_id = ? AND fase = 'winners'"
    );
    $stmt->execute([$campeonato_id]);
    $ultima_rodada = (int) $stmt->fetchColumn();
    
    if ($ultima_rodada < 1) return;
    
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'winners' AND rodada = ? AND vencedor IS NULL"
    );
    $stmt->execute([$campeonato_id, $ultima_rodada]);
    
    if ($stmt->fetchColumn() > 0) return;
    
    $stmt = $pdo->prepare(
        "SELECT vencedor FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'winners' AND rodada = ? AND vencedor IS NOT NULL"
    );
    $stmt->execute([$campeonato_id, $ultima_rodada]);
    $vencedores = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($vencedores) < 2) return;
    
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'winners' AND rodada = ?"
    );
    $stmt->execute([$campeonato_id, $ultima_rodada + 1]);
    
    if ($stmt->fetchColumn() > 0) return;
    
    shuffle($vencedores);
    for ($i = 0; $i < count($vencedores); $i += 2) {
        $time1 = $vencedores[$i];
        $time2 = isset($vencedores[$i + 1]) ? $vencedores[$i + 1] : null;
        
        if ($time2) {
            $stmt = $pdo->prepare(
                "INSERT INTO confrontos (campeonato_id, time1, time2, fase, rodada) 
                 VALUES (?, ?, ?, 'winners', ?)"
            );
            $stmt->execute([$campeonato_id, $time1, $time2, $ultima_rodada + 1]);
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO confrontos (campeonato_id, time1, vencedor, fase, rodada) 
                 VALUES (?, ?, ?, 'winners', ?)"
            );
            $stmt->execute([$campeonato_id, $time1, $time1, $ultima_rodada + 1]);
        }
    }
}

function gerarProximaRodadaLosers($pdo, $campeonato_id) {
    $stmt = $pdo->prepare(
        "SELECT MAX(rodada) FROM confrontos WHERE campeonato_id = ? AND fase = 'losers'"
    );
    $stmt->execute([$campeonato_id]);
    $ultima_rodada = (int) $stmt->fetchColumn();
    
    if ($ultima_rodada < 1) return;
    
    // Check if the current losers round is complete (only full matches matter)
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'losers' AND rodada = ? 
         AND time1 IS NOT NULL AND time2 IS NOT NULL AND vencedor IS NULL"
    );
    $stmt->execute([$campeonato_id, $ultima_rodada]);
    
    if ($stmt->fetchColumn() > 0) return;  // If any full matches are undecided, don't advance
    
    // Collect winners from the current losers round
    $stmt = $pdo->prepare(
        "SELECT vencedor FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'losers' AND rodada = ? AND vencedor IS NOT NULL"
    );
    $stmt->execute([$campeonato_id, $ultima_rodada]);
    $vencedores_losers = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $vencedores_losers = array_unique($vencedores_losers);
    $vencedores_validos = array_values(array_filter($vencedores_losers, function($time_id) use ($pdo, $campeonato_id) {
        return !timeEstaEliminado($pdo, $campeonato_id, $time_id);
    }));
    
    if (count($vencedores_validos) === 0) return;
    
    // If only 1 winner, stop here—the losers bracket is done, let verificarEFinal handle the final
    if (count($vencedores_validos) === 1) {
        return;
    }
    
    $proxima_rodada = $ultima_rodada + 1;
    
    // Add each valid winner to the next losers round (fill slots or create new matches)
    foreach ($vencedores_validos as $time_id) {
        if (timeEstaEliminado($pdo, $campeonato_id, $time_id)) {
            continue;
        }
        
        if (timeEstaNoLosersBracket($pdo, $campeonato_id, $time_id)) {
            continue;  // Already in the bracket
        }
        
        // Find an existing match in the next round with time2 null (empty slot)
        $stmt = $pdo->prepare(
            "SELECT id FROM confrontos 
             WHERE campeonato_id = ? AND fase = 'losers' 
             AND rodada = ? AND time2 IS NULL AND vencedor IS NULL
             ORDER BY id LIMIT 1"
        );
        $stmt->execute([$campeonato_id, $proxima_rodada]);
        $confronto_existente = $stmt->fetch();
        
        if ($confronto_existente) {
            // Fill the empty slot
            $stmt = $pdo->prepare("UPDATE confrontos SET time2 = ? WHERE id = ?");
            $stmt->execute([$time_id, $confronto_existente["id"]]);
        } else {
            // Create a new match with this team as time1
            $stmt = $pdo->prepare(
                "INSERT INTO confrontos (campeonato_id, time1, fase, rodada) 
                 VALUES (?, ?, 'losers', ?)"
            );
            $stmt->execute([$campeonato_id, $time_id, $proxima_rodada]);
        }
    }
}

function repararRodadasLosers($pdo, $campeonato_id) {
    // Get the maximum losers round
    $stmt = $pdo->prepare(
        "SELECT MAX(rodada) FROM confrontos WHERE campeonato_id = ? AND fase = 'losers'"
    );
    $stmt->execute([$campeonato_id]);
    $max_rodada = (int) $stmt->fetchColumn();
    
    if ($max_rodada < 2) return;  // No rounds to repair yet
    
    // Start from round 2 and go up
    for ($rodada = 2; $rodada <= $max_rodada; $rodada++) {
        $prev_rodada = $rodada - 1;
        
        // Get winners from the previous losers round who are not eliminated
        $stmt = $pdo->prepare(
            "SELECT vencedor FROM confrontos 
             WHERE campeonato_id = ? AND fase = 'losers' AND rodada = ? AND vencedor IS NOT NULL"
        );
        $stmt->execute([$campeonato_id, $prev_rodada]);
        $vencedores_prev = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $vencedores_prev = array_unique($vencedores_prev);
        
        foreach ($vencedores_prev as $time_id) {
            if (timeEstaEliminado($pdo, $campeonato_id, $time_id)) {
                continue;  // Skip eliminated teams
            }
            
            // Check if this team is already in the current losers round
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM confrontos 
                 WHERE campeonato_id = ? AND fase = 'losers' AND rodada = ? 
                 AND (time1 = ? OR time2 = ?)"
            );
            $stmt->execute([$campeonato_id, $rodada, $time_id, $time_id]);
            
            if ($stmt->fetchColumn() > 0) {
                continue;  // Already in this round
            }
            
            // Team is missing—add it to the current round
            // Find an empty slot (match with time2 null)
            $stmt = $pdo->prepare(
                "SELECT id FROM confrontos 
                 WHERE campeonato_id = ? AND fase = 'losers' 
                 AND rodada = ? AND time2 IS NULL AND vencedor IS NULL
                 ORDER BY id LIMIT 1"
            );
            $stmt->execute([$campeonato_id, $rodada]);
            $confronto_existente = $stmt->fetch();
            
            if ($confronto_existente) {
                // Fill the slot
                $stmt = $pdo->prepare("UPDATE confrontos SET time2 = ? WHERE id = ?");
                $stmt->execute([$time_id, $confronto_existente["id"]]);
            } else {
                // Create a new match
                $stmt = $pdo->prepare(
                    "INSERT INTO confrontos (campeonato_id, time1, fase, rodada) 
                     VALUES (?, ?, 'losers', ?)"
                );
                $stmt->execute([$campeonato_id, $time_id, $rodada]);
            }
        }
    }
}

function verificarEFinal($pdo, $campeonato_id) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM confrontos WHERE campeonato_id = ? AND fase IN ('final', 'grande_final')"
    );
    $stmt->execute([$campeonato_id]);
    
    if ($stmt->fetchColumn() > 0) return;
    
    $stmt = $pdo->prepare(
        "SELECT vencedor FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'winners' 
         ORDER BY rodada DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$campeonato_id]);
    $vencedor_winners = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare(
        "SELECT vencedor FROM confrontos 
         WHERE campeonato_id = ? AND fase = 'losers' 
         ORDER BY rodada DESC, id DESC LIMIT 1"
    );
    $stmt->execute([$campeonato_id]);
    $vencedor_losers = $stmt->fetchColumn();
    
    if ($vencedor_winners && $vencedor_losers) {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM confrontos 
             WHERE campeonato_id = ? AND fase = 'winners' AND vencedor IS NULL"
        );
        $stmt->execute([$campeonato_id]);
        $winners_pendentes = $stmt->fetchColumn();
        
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM confrontos 
             WHERE campeonato_id = ? AND fase = 'losers' AND vencedor IS NULL"
        );
        $stmt->execute([$campeonato_id]);
        $losers_pendentes = $stmt->fetchColumn();
        
        if ($winners_pendentes == 0 && $losers_pendentes == 0) {
            $stmt = $pdo->prepare(
                "INSERT INTO confrontos (campeonato_id, time1, time2, fase, rodada) 
                 VALUES (?, ?, ?, 'final', 1)"
            );
            $stmt->execute([$campeonato_id, $vencedor_winners, $vencedor_losers]);
        }
    }
}