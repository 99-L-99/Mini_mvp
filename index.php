<?php
session_start();
require_once "conexao.php";

function h($v): string
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

// Converte valor do banco/POST para o formato do <input type="datetime-local">
function dtLocal($v): string
{
    if (!$v) return '';
    if (strpos($v, 'T') !== false) return substr($v, 0, 16);
    $t = strtotime($v);
    return $t ? date('Y-m-d\TH:i', $t) : '';
}

function redirecionar(string $tipo, string $msg): void
{
    $_SESSION['flash'] = ['tipo' => $tipo, 'msg' => $msg];
    header("Location: index.php");
    exit;
}

$statusValidos = ['planejado', 'aberto', 'encerrado', 'cancelado'];

// Dados para os selects
$usuarios = $pdo->query(
    "SELECT id_usuario, nome FROM usuarios
     WHERE tipo IN ('administrador', 'organizador') ORDER BY nome"
)->fetchAll();

$locais = $pdo->query(
    "SELECT id_local, nome, cidade, capacidade FROM locais ORDER BY nome"
)->fetchAll();

$categorias = $pdo->query(
    "SELECT nome FROM categorias ORDER BY nome"
)->fetchAll(PDO::FETCH_COLUMN);

// Valida os dados do formulário. Retorna [dados_limpos, erros]
function validar(PDO $pdo, array $in, array $statusValidos): array
{
    $erros = [];

    $nome      = trim($in['nome'] ?? '');
    $descricao = trim($in['descricao'] ?? '');
    $categoria = trim($in['categoria'] ?? '');
    $status    = $in['status'] ?? '';

    if ($nome === '' || mb_strlen($nome) > 150) {
        $erros[] = "Informe o nome do evento (até 150 caracteres).";
    }
    if (mb_strlen($categoria) > 80) {
        $erros[] = "A categoria deve ter no máximo 80 caracteres.";
    }
    if (!in_array($status, $statusValidos, true)) {
        $erros[] = "Status inválido.";
    }

    $inicio = DateTime::createFromFormat('Y-m-d\TH:i', $in['data_inicio'] ?? '');
    $fim    = DateTime::createFromFormat('Y-m-d\TH:i', $in['data_fim'] ?? '');
    if (!$inicio || !$fim) {
        $erros[] = "Informe datas de início e término válidas.";
    } elseif ($fim <= $inicio) {
        $erros[] = "A data de término deve ser posterior à data de início.";
    }

    $capacidade = filter_var($in['capacidade'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($capacidade === false) {
        $erros[] = "A capacidade deve ser um número inteiro maior que zero.";
    }

    $preco = $in['preco'] ?? '';
    if (!is_numeric($preco) || (float)$preco < 0) {
        $erros[] = "O preço deve ser um número maior ou igual a zero.";
    }

    $idUsuario = filter_var($in['id_usuario'] ?? '', FILTER_VALIDATE_INT);
    if ($idUsuario === false) {
        $erros[] = "Selecione o organizador.";
    } else {
        $q = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_usuario = ?");
        $q->execute([$idUsuario]);
        if (!$q->fetchColumn()) {
            $erros[] = "Organizador não encontrado.";
        }
    }

    $idLocal = null;
    if (($in['id_local'] ?? '') !== '') {
        $idLocal = filter_var($in['id_local'], FILTER_VALIDATE_INT);
        if ($idLocal === false) {
            $erros[] = "Local inválido.";
            $idLocal = null;
        } else {
            $q = $pdo->prepare("SELECT capacidade FROM locais WHERE id_local = ?");
            $q->execute([$idLocal]);
            $capLocal = $q->fetchColumn();
            if ($capLocal === false) {
                $erros[] = "Local não encontrado.";
            } elseif ($capacidade !== false && $capacidade > (int)$capLocal) {
                $erros[] = "A capacidade do evento ($capacidade) excede a do local ($capLocal).";
            }
        }
    }

    $dados = [
        $nome,
        $descricao !== '' ? $descricao : null,
        $categoria !== '' ? $categoria : null,
        $inicio ? $inicio->format('Y-m-d H:i:s') : null,
        $fim ? $fim->format('Y-m-d H:i:s') : null,
        $capacidade,
        $preco,
        $status,
        $idUsuario,
        $idLocal,
    ];

    return [$dados, $erros];
}

$erros    = [];
$formPost = null; // guarda o que o usuário digitou quando há erro

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    try {
        if ($acao === 'cadastrar' || $acao === 'atualizar') {

            [$dados, $erros] = validar($pdo, $_POST, $statusValidos);

            $idEvento = null;
            if ($acao === 'atualizar') {
                $idEvento = filter_var($_POST['id_evento'] ?? '', FILTER_VALIDATE_INT);
                if ($idEvento === false) {
                    $erros[] = "Evento inválido.";
                }
            }

            if (!$erros) {
                if ($acao === 'cadastrar') {
                    $stmt = $pdo->prepare(
                        "INSERT INTO eventos
                         (nome, descricao, categoria, data_inicio, data_fim,
                          capacidade, preco, status, id_usuario, id_local)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->execute($dados);
                    redirecionar('ok', 'Evento cadastrado com sucesso.');
                } else {
                    $stmt = $pdo->prepare(
                        "UPDATE eventos SET
                         nome = ?, descricao = ?, categoria = ?,
                         data_inicio = ?, data_fim = ?, capacidade = ?,
                         preco = ?, status = ?, id_usuario = ?, id_local = ?
                         WHERE id_evento = ?"
                    );
                    $stmt->execute(array_merge($dados, [$idEvento]));
                    redirecionar('ok', 'Evento atualizado com sucesso.');
                }
            }

            $formPost = $_POST;

        } elseif ($acao === 'excluir') {

            $idEvento = filter_var($_POST['id_evento'] ?? '', FILTER_VALIDATE_INT);
            if ($idEvento === false) {
                redirecionar('erro', 'Evento inválido.');
            }

            $q = $pdo->prepare("SELECT status FROM eventos WHERE id_evento = ?");
            $q->execute([$idEvento]);
            $statusEvento = $q->fetchColumn();
            if ($statusEvento === false) {
                redirecionar('erro', 'Evento não encontrado.');
            }

            $encerrado = in_array($statusEvento, ['encerrado', 'cancelado'], true);

            $q = $pdo->prepare("SELECT COUNT(*) FROM inscricoes WHERE id_evento = ?");
            $q->execute([$idEvento]);
            $qtdInscricoes = (int)$q->fetchColumn();

            if (!$encerrado && $qtdInscricoes > 0) {
                redirecionar(
                    'erro',
                    'Só é possível excluir eventos com inscrições quando estiverem encerrados ou cancelados. Altere o status do evento primeiro.'
                );
            }

            // Apaga pagamentos e inscrições junto, tudo ou nada
            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                "DELETE FROM pagamentos
                 WHERE id_inscricao IN (SELECT id_inscricao FROM inscricoes WHERE id_evento = ?)"
            );
            $stmt->execute([$idEvento]);

            $stmt = $pdo->prepare("DELETE FROM inscricoes WHERE id_evento = ?");
            $stmt->execute([$idEvento]);

            $stmt = $pdo->prepare("DELETE FROM eventos WHERE id_evento = ?");
            $stmt->execute([$idEvento]);

            $pdo->commit();

            redirecionar('ok', 'Evento excluído.');
        }

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log($e->getMessage());
        $erros[] = "Erro ao acessar o banco de dados. Tente novamente.";
        if ($acao === 'cadastrar' || $acao === 'atualizar') {
            $formPost = $_POST;
        }
    }
}

// Mensagem de sucesso/erro vinda de um redirecionamento
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Evento a editar (via link ?editar=ID)
$editar = null;
if (!$formPost && isset($_GET['editar'])) {
    $idEditar = filter_var($_GET['editar'], FILTER_VALIDATE_INT);
    if ($idEditar !== false) {
        $stmt = $pdo->prepare("SELECT * FROM eventos WHERE id_evento = ?");
        $stmt->execute([$idEditar]);
        $editar = $stmt->fetch() ?: null;
    }
}

$f        = $formPost ?? $editar ?? [];
$idForm   = $formPost ? ($formPost['id_evento'] ?? null) : ($editar['id_evento'] ?? null);
$editando = $idForm !== null && $idForm !== '';

// Listagem
$eventos = $pdo->query(
    "SELECT e.*, u.nome AS organizador, l.nome AS local_nome,
            (SELECT COUNT(*) FROM inscricoes i WHERE i.id_evento = e.id_evento) AS inscritos
     FROM eventos e
     JOIN usuarios u ON u.id_usuario = e.id_usuario
     LEFT JOIN locais l ON l.id_local = e.id_local
     ORDER BY e.id_evento DESC"
)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gerenciador de Eventos</title>
    <style>
        :root {
            --fundo: #e9edf3;
            --painel: #ffffff;
            --tinta: #14203a;
            --tinta-suave: #55607a;
            --linha: #d8dee9;
            --acento: #2540d9;
            --acento-escuro: #1b2fa8;
            --perigo: #b3261e;
            --perigo-fundo: #fdecea;
            --ok: #1e7a4c;
            --ok-fundo: #e5f4ec;
            --fonte: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: var(--fonte);
            font-size: 16px;
            line-height: 1.5;
            color: var(--tinta);
            background: var(--fundo);
        }

        /* ---------- Topo ---------- */

        .topo {
            background: var(--tinta);
            color: #fff;
            padding: 28px 0 32px;
        }

        .topo h1 {
            margin: 0;
            font-size: 1.9rem;
            font-weight: 700;
            letter-spacing: -0.02em;
        }

        .topo p {
            margin: 4px 0 0;
            color: #aab4cf;
        }

        .container {
            width: min(1280px, 100% - 40px);
            margin-inline: auto;
        }

        /* ---------- Layout ---------- */

        .layout {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            gap: 28px;
            align-items: start;
            margin-block: 28px 56px;
        }

        .painel {
            background: var(--painel);
            border: 1px solid var(--linha);
            border-radius: 12px;
            padding: 24px;
        }

        .painel h2 {
            margin: 0 0 18px;
            font-size: 1.2rem;
            font-weight: 700;
            letter-spacing: -0.01em;
        }

        .formulario {
            position: sticky;
            top: 20px;
        }

        /* ---------- Avisos ---------- */

        .aviso {
            border-radius: 8px;
            padding: 12px 16px;
            margin: 0 0 18px;
            font-weight: 500;
        }

        .aviso ul {
            margin: 0;
            padding-left: 18px;
        }

        .aviso-ok {
            background: var(--ok-fundo);
            color: var(--ok);
        }

        .aviso-erro {
            background: var(--perigo-fundo);
            color: var(--perigo);
        }

        /* ---------- Formulário ---------- */

        .grade {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .campo {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 0;
        }

        .campo.inteiro {
            grid-column: 1 / -1;
        }

        .campo > span {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--tinta-suave);
        }

        input[type="text"],
        input[type="number"],
        input[type="datetime-local"],
        select,
        textarea {
            width: 100%;
            font: inherit;
            color: var(--tinta);
            background: #f8f9fc;
            border: 1px solid var(--linha);
            border-radius: 6px;
            padding: 9px 11px;
            transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
        }

        textarea {
            min-height: 84px;
            resize: vertical;
        }

        input:hover,
        select:hover,
        textarea:hover {
            border-color: #b7c0d3;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: none;
            background: #fff;
            border-color: var(--acento);
            box-shadow: 0 0 0 3px rgba(37, 64, 217, 0.18);
        }

        .acoes-form {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
            gap: 14px;
            margin-top: 4px;
        }

        /* ---------- Botões ---------- */

        .btn {
            font: inherit;
            font-weight: 600;
            border: 1px solid transparent;
            border-radius: 6px;
            padding: 10px 18px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            line-height: 1.3;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }

        .btn-principal {
            background: var(--acento);
            color: #fff;
        }

        .btn-principal:hover {
            background: var(--acento-escuro);
        }

        .btn-link {
            color: var(--tinta-suave);
            padding-inline: 4px;
        }

        .btn-link:hover {
            color: var(--tinta);
            text-decoration: underline;
        }

        .btn-peq {
            padding: 5px 11px;
            font-size: 0.875rem;
            background: transparent;
        }

        .btn-editar {
            color: var(--acento);
            border-color: #c5cdf3;
        }

        .btn-editar:hover {
            background: #eef1fd;
            border-color: var(--acento);
        }

        .btn-excluir {
            color: var(--perigo);
            border-color: #efc6c3;
        }

        .btn-excluir:hover {
            background: var(--perigo-fundo);
            border-color: var(--perigo);
        }

        a:focus-visible,
        button:focus-visible {
            outline: 3px solid rgba(37, 64, 217, 0.45);
            outline-offset: 2px;
        }

        /* ---------- Tabela ---------- */

        .listagem .cabecalho {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            margin-bottom: 14px;
        }

        .listagem h2 {
            margin: 0;
        }

        .contagem {
            color: var(--tinta-suave);
            font-size: 0.9rem;
        }

        .tabela-wrap {
            overflow-x: auto;
            margin-inline: -24px;
            padding-inline: 24px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        th {
            text-align: left;
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--tinta-suave);
            padding: 0 12px 10px;
            border-bottom: 2px solid var(--linha);
            white-space: nowrap;
        }

        td {
            padding: 14px 12px;
            border-bottom: 1px solid var(--linha);
            vertical-align: top;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tr.editando td {
            background: #f1f4fe;
        }

        .evento-nome {
            display: block;
            font-weight: 700;
        }

        .evento-meta {
            display: block;
            font-size: 0.875rem;
            color: var(--tinta-suave);
        }

        .num {
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .vazio {
            text-align: center;
            color: var(--tinta-suave);
            padding: 36px 12px;
        }

        /* ---------- Ocupação ---------- */

        .ocupacao {
            min-width: 120px;
        }

        .ocupacao-texto {
            font-size: 0.875rem;
            font-variant-numeric: tabular-nums;
        }

        .barra {
            height: 8px;
            margin-top: 6px;
            background: #e2e7f1;
            border-radius: 999px;
            overflow: hidden;
        }

        .barra span {
            display: block;
            height: 100%;
            background: var(--acento);
            border-radius: 999px;
        }

        .barra.cheia span {
            background: var(--ok);
        }

        /* ---------- Status ---------- */

        .status {
            display: inline-block;
            font-size: 0.8125rem;
            font-weight: 600;
            padding: 3px 11px;
            border-radius: 999px;
            white-space: nowrap;
        }

        .status-planejado {
            background: #e8ecf6;
            color: #3c4a6b;
        }

        .status-aberto {
            background: var(--ok-fundo);
            color: var(--ok);
        }

        .status-encerrado {
            background: #ececec;
            color: #555;
        }

        .status-cancelado {
            background: var(--perigo-fundo);
            color: var(--perigo);
        }

        /* ---------- Ações na tabela ---------- */

        .acoes {
            display: flex;
            gap: 8px;
            align-items: center;
        }

        .acoes form {
            margin: 0;
        }

        /* ---------- Responsivo ---------- */

        @media (max-width: 960px) {
            .layout {
                grid-template-columns: minmax(0, 1fr);
            }

            .formulario {
                position: static;
            }
        }

        @media (max-width: 480px) {
            .grade {
                grid-template-columns: 1fr;
            }

            .painel {
                padding: 18px;
            }

            .tabela-wrap {
                margin-inline: -18px;
                padding-inline: 18px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                transition: none !important;
            }
        }
    </style>
</head>
<body>

<header class="topo">
    <div class="container">
        <h1>Gerenciador de Eventos</h1>
        <p>Cadastre, edite e acompanhe a ocupação dos seus eventos.</p>
    </div>
</header>

<main class="container layout">

    <!-- ========== FORMULÁRIO ========== -->
    <section class="painel formulario">

        <h2><?= $editando ? "Editar evento" : "Novo evento" ?></h2>

        <?php if ($flash): ?>
            <div class="aviso <?= $flash['tipo'] === 'ok' ? 'aviso-ok' : 'aviso-erro' ?>" role="alert">
                <?= h($flash['msg']) ?>
            </div>
        <?php endif; ?>

        <?php if ($erros): ?>
            <div class="aviso aviso-erro" role="alert">
                <ul>
                    <?php foreach ($erros as $erro): ?>
                        <li><?= h($erro) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="index.php" class="grade" autocomplete="off">

            <?php if ($editando): ?>
                <input type="hidden" name="id_evento" value="<?= h($idForm) ?>">
            <?php endif; ?>

            <label class="campo inteiro">
                <span>Nome do evento</span>
                <input type="text" name="nome" maxlength="150"
                       value="<?= h($f['nome'] ?? '') ?>" required>
            </label>

            <label class="campo inteiro">
                <span>Descrição</span>
                <textarea name="descricao"><?= h($f['descricao'] ?? '') ?></textarea>
            </label>

            <label class="campo inteiro">
                <span>Categoria</span>
                <select name="categoria">
                    <option value="">Sem categoria</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?= h($cat) ?>" <?= (($f['categoria'] ?? '') === $cat) ? 'selected' : '' ?>>
                            <?= h($cat) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="campo inteiro">
                <span>Início</span>
                <input type="datetime-local" name="data_inicio"
                       value="<?= h(dtLocal($f['data_inicio'] ?? '')) ?>" required>
            </label>

            <label class="campo inteiro">
                <span>Término</span>
                <input type="datetime-local" name="data_fim"
                       value="<?= h(dtLocal($f['data_fim'] ?? '')) ?>" required>
            </label>

            <label class="campo">
                <span>Capacidade</span>
                <input type="number" name="capacidade" min="1"
                       value="<?= h($f['capacidade'] ?? '') ?>" required>
            </label>

            <label class="campo">
                <span>Preço (R$)</span>
                <input type="number" name="preco" step="0.01" min="0"
                       value="<?= h($f['preco'] ?? '0') ?>" required>
            </label>

            <label class="campo inteiro">
                <span>Status</span>
                <select name="status">
                    <?php foreach ($statusValidos as $opcao): ?>
                        <option value="<?= h($opcao) ?>"
                            <?= (($f['status'] ?? 'planejado') === $opcao) ? 'selected' : '' ?>>
                            <?= h(ucfirst($opcao)) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="campo inteiro">
                <span>Organizador</span>
                <select name="id_usuario" required>
                    <option value="">Selecione</option>
                    <?php foreach ($usuarios as $u): ?>
                        <option value="<?= h($u['id_usuario']) ?>"
                            <?= ((string)($f['id_usuario'] ?? '') === (string)$u['id_usuario']) ? 'selected' : '' ?>>
                            <?= h($u['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="campo inteiro">
                <span>Local</span>
                <select name="id_local">
                    <option value="">Sem local definido</option>
                    <?php foreach ($locais as $l): ?>
                        <option value="<?= h($l['id_local']) ?>"
                            <?= ((string)($f['id_local'] ?? '') === (string)$l['id_local']) ? 'selected' : '' ?>>
                            <?= h($l['nome']) ?>, <?= h($l['cidade']) ?> (cabem <?= h($l['capacidade']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <div class="acoes-form">
                <?php if ($editando): ?>
                    <button type="submit" name="acao" value="atualizar" class="btn btn-principal">Salvar alterações</button>
                    <a href="index.php" class="btn btn-link">Cancelar</a>
                <?php else: ?>
                    <button type="submit" name="acao" value="cadastrar" class="btn btn-principal">Cadastrar evento</button>
                    <a href="index.php" class="btn btn-link">Limpar</a>
                <?php endif; ?>
            </div>

        </form>
    </section>

    <!-- ========== LISTAGEM ========== -->
    <section class="painel listagem">

        <div class="cabecalho">
            <h2>Eventos cadastrados</h2>
            <span class="contagem"><?= count($eventos) ?> <?= count($eventos) === 1 ? 'evento' : 'eventos' ?></span>
        </div>

        <div class="tabela-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Evento</th>
                        <th>Início</th>
                        <th>Inscritos</th>
                        <th>Preço</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>

                <?php if (!$eventos): ?>
                    <tr><td colspan="6" class="vazio">Nenhum evento ainda. Use o formulário para cadastrar o primeiro.</td></tr>
                <?php endif; ?>

                <?php foreach ($eventos as $evento):
                    $cap = max(1, (int)$evento['capacidade']);
                    $pct = min(100, (int)round(((int)$evento['inscritos'] / $cap) * 100));
                    $emEdicao = $editando && (string)$idForm === (string)$evento['id_evento'];
                ?>
                    <tr class="<?= $emEdicao ? 'editando' : '' ?>">
                        <td>
                            <span class="evento-nome"><?= h($evento['nome']) ?></span>
                            <?php if ($evento['categoria']): ?>
                                <span class="evento-meta"><?= h($evento['categoria']) ?></span>
                            <?php endif; ?>
                            <span class="evento-meta">
                                <?= h($evento['local_nome'] ?? 'Local a definir') ?>, organizado por <?= h($evento['organizador']) ?>
                            </span>
                        </td>
                        <td class="num"><?= h(date('d/m/Y', strtotime($evento['data_inicio']))) ?><br>
                            <span class="evento-meta"><?= h(date('H:i', strtotime($evento['data_inicio']))) ?></span>
                        </td>
                        <td class="ocupacao">
                            <span class="ocupacao-texto"><?= h($evento['inscritos']) ?> de <?= h($evento['capacidade']) ?></span>
                            <div class="barra <?= $pct >= 100 ? 'cheia' : '' ?>">
                                <span style="width: <?= $pct ?>%"></span>
                            </div>
                        </td>
                        <td class="num">R$ <?= number_format((float)$evento['preco'], 2, ',', '.') ?></td>
                        <td>
                            <span class="status status-<?= h($evento['status']) ?>">
                                <?= h(ucfirst($evento['status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="acoes">
                                <a href="?editar=<?= h($evento['id_evento']) ?>" class="btn btn-peq btn-editar">Editar</a>
                                <?php
                                $msgExcluir = 'Deseja excluir este evento?';
                                if (in_array($evento['status'], ['encerrado', 'cancelado'], true) && (int)$evento['inscritos'] > 0) {
                                    $msgExcluir = 'Excluir este evento também apagará as ' . (int)$evento['inscritos']
                                        . ' inscrição(ões) e os pagamentos relacionados. Continuar?';
                                }
                                ?>
                                <form method="POST" action="index.php"
                                      onsubmit="return confirm(<?= h(json_encode($msgExcluir)) ?>)">
                                    <input type="hidden" name="id_evento" value="<?= h($evento['id_evento']) ?>">
                                    <button type="submit" name="acao" value="excluir" class="btn btn-peq btn-excluir">Excluir</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                </tbody>
            </table>
        </div>
    </section>

</main>

</body>
</html>