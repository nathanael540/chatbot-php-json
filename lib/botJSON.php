<?php
/**
 * Chatbot com scripts de conversas em JSON
 */
class botJSON
{
    /**
     * Scripts das conversas em JSON
     * @var array
     */
    public $scripts = [];

    /**
     * Script de conversa atual
     * @var object
     */
    public $script = null;

    /**
     * Parte do script da conversa atual
     * @var object
     */
    public $fluxo = null;

    /**
     * Tipo de execucao de fluxo entrada ou saida
     * @var bool
     */
    public $entrada = true;

    /**
     * Indica a alternativa escolhida em um fluxo de escolha
     * @param object
     */
    public $alternativa = null;

    /**
     * Usuarío atual
     * @var String
     */
    public $usuario = "";

    /**
     * Mensagem atual
     * @var String
     */
    public $mensagem = "";

    /**
     * Variáveis da conversa atual
     * @var object
     */
    public $conversa = null;

    /**
     * Resposta atual
     * @var String
     */
    public $resposta = "";

    /**
     * Errors no processamento da conversa
     * @var array
     */
    public $erros = [];

    /**
     * Objeto que sera retornado como resposta JSON
     * @var array
     */
    public $conteudo = [];



    /**
     * Construtor da classe
     */
    public function __construct()
    {
        // Carrega os dados da requisição: usuário e mensagem
        $this->carregaParametros();

        // Carrega os scripts de conversas: JSONs e Funções PHP
        $this->carregaScripts();

        // Verifica se temos uma conversa em curso com o usuário
        $this->checaHistorico();
    }

    /**
     * Carrega os dados da requisição e faz as verificações iniciais
     */
    public function carregaParametros()
    {
        // Se a variável $_POST estiver vazia, tenta carregar os dados da requisição de outra forma
        if (empty($_POST)) {
            $_POST = json_decode(file_get_contents('php://input'), true);
        }

        // Verifica se o usuário foi informado
        $this->usuario = $_POST['usuario'] ?? $_POST['sender'] ?? null;
        if (empty($this->usuario)) {
            $this->erros[] = 'Usuário não informado na requisição!';
        }

        // Verifica se a mensagem foi informada
        $this->mensagem = $_POST['mensagem'] ?? $_POST['message'] ?? null;
        if (empty($this->mensagem)) {
            $this->erros[] = 'Mensagem não informada na requisição!';
        }

        // Encerra a execução se houver erros
        $this->verificaErros();
    }

    /**
     * Carrega os scripts de conversas
     */
    public function carregaScripts()
    {
        // Carrega os scripts de conversas dos arquivos JSON
        $scripts = glob(SCRIPTS_PATH . '/{,*/,*/*/,*/*/*/}*.{json}', GLOB_BRACE);

        // Carrega os scripts de conversas em memória em formato de array de objetos
        foreach ($scripts as $script) {
            $conversas = json_decode(file_get_contents($script), false);
            foreach ($conversas as $inicialiador => $conversa) {
                $this->scripts[$inicialiador] = $conversa;
            }
        }

        // Carrega funções php para serem usadas nos scripts de conversas
        $scripts = glob(SCRIPTS_PATH . '/{,*/,*/*/,*/*/*/}*.{php}', GLOB_BRACE);
        foreach ($scripts as $script) {
            include $script;
        }

        // Verifica se temos pelo menos um script de conversa
        if (empty($this->scripts)) {
            $this->erros[] = 'Não foi encontrado nenhum script JSON de conversa!';
        }

        // Encerra a execução se houver erros
        $this->verificaErros();
    }

    /**
     * Verifica se temos uma conversa em curso com o usuário
     */
    public function checaHistorico()
    {
        // Verifica se já temos uma conversa em curso com o usuário
        $this->obtemConversa();

        if (empty($this->conversa)) {
            // Se não tiver, tenta iniciar uma nova conversa
            $this->iniciaConversa();
        } else {
            // Se tiver, verifica se a mensagem do usuário é um comando
            $this->checaComando();
        }
    }

    /**
     * Verifica se a mensagem do usuário é um comando
     */
    public function checaComando()
    {
        // Obtem o fluxo do script pela hash anterior
        $this->fluxo = $this->obtemFluxo();

        // Executa o fluxo do script de conversa
        $this->executaFluxo();
    }


    /**
     * Inicia uma nova conversa
     */
    public function iniciaConversa()
    {
        // Obtem o script de conversa inicial ou encerra a execução
        $this->checaPalavrasChaves();

        // Seleciona o primeiro fluxo do script de conversa
        $this->fluxo = $this->script;

        // Executa o fluxo do script de conversa
        $this->executaFluxo();
    }

    /**
     * Verifica se a mensagem do usuário é um inicializador de conversa
     */
    public function checaPalavrasChaves()
    {
        foreach ($this->scripts as $text => $flow) {
            if (stripos($text, $this->mensagem) !== false) {
                $this->script = $flow;
                break;
            }
        }

        // Verifica se encontramos um script de conversa
        if (empty($this->script)) {
            $this->erros[] = 'Não foi encontrado nenhum script de conversa para a mensagem: ' . $this->mensagem;
        }

        // Encerra a execução se houver erros
        $this->verificaErros();

        // TODO: Adicionar uma mensagem padrão para quando não encontrar um script de conversa
    }

    /**
     * Executa o script de conversa
     */
    public function executaFluxo()
    {
        if (empty($this->fluxo)) {
            $this->erros[] = 'Não foi encontrado nenhum fluxo para a mensagem: ' . $this->mensagem;
            $this->verificaErros();
        }


        // Obtem o tipo do fluxo
        $tipo = $this->fluxo->tipo;

        // verifica se é uma execução de entrada
        $this->entrada = empty($this->conversa) || $this->fluxo->hash != $this->conversa->hash;

        switch ($tipo) {
            case 'pergunta':
                $this->_fluxoPergunta();
                break;
            case 'escolha':
                $this->_fluxoEscolha();
                break;

            default:
                $this->erros[] = 'Tipo de fluxo não encontrado: ' . $tipo;
                break;
        }

        // Processa opções do proximo fluxo
        $this->checaProximoFluxo();

        // Processa opções da alternativa
        $this->checaAlternativa();

        // Retorna a resposta para o usuário
        $this->responde();
    }

    /**
     * Processa opções da alternativa
     */
    public function checaAlternativa()
    {
        if (empty($this->alternativa)) {
            return;
        }

        // Salva a alternativa localmente e nula a alternativa
        $alternativa = $this->alternativa;
        $this->alternativa = null;

        // Verifica se a alternativa é um final
        if ($alternativa->tipo == 'final') {
            $this->deletaConversa();
        }

        // Verifica se a alternativa é um flow
        if ($alternativa->tipo == 'flow') {
            $destino = $alternativa->proximo;

            // Salva a hash do proximo fluxo
            $this->salvaConversa($destino);

            // Obtem o fluxo do script pela hash e gera a resposta do fluxo
            $flow = $this->obtemFluxo($destino);

            if (!empty($flow)) {
                $this->resposta .= "\r\n----------\r\n\r\n";
                $this->_criaTextoResposta($flow);
            }
        }
    }

    /**
     * Verifica se devemos informar algo a mais do proximo fluxo
     */
    function checaProximoFluxo()
    {

        // Deleta o histórico da conversa se o fluxo for final
        if ($this->fluxo->tipo == 'final') {
            $this->deletaConversa();
        }

        // Verifica se o fluxo atual é um flow
        if ($this->fluxo->tipo == 'flow') {
            $destino = $this->fluxo->proximo;

            // Salva a hash do proximo fluxo
            $this->salvaConversa($destino);

            // Obtem o fluxo do script pela hash e gera a resposta do fluxo
            $flow = $this->obtemFluxo($destino);

            if (!empty($flow)) {
                $this->resposta .= "\r\n----------\r\n\r\n";
                $this->_criaTextoResposta($flow);
            }
        }

        // Retorna se a gente não tiver um proximo fluxo
        if (!isset($this->fluxo->proximo)) {
            return;
        }

        // Verifica se o proximo fluxo é um flow
        if ($this->fluxo->proximo->tipo == 'flow') {
            $destino = $this->fluxo->proximo->proximo;

            // Salva a hash do proximo fluxo
            $this->salvaConversa($destino);

            // Obtem o fluxo do script pela hash e gera a resposta do fluxo
            $flow = $this->obtemFluxo($destino);

            if (!empty($flow)) {

                $this->resposta .= "\r\n----------\r\n\r\n";

                $this->_criaTextoResposta($flow);
            }
        }

        // Verifica se o proximo fluxo é uma api
        if ($this->fluxo->proximo->tipo == 'api' && !$this->entrada) {
            $this->resposta = "";
            $this->_fluxoApi();
        }
    }

    /**
     * Verifica se houve erros no processamento da conversa e encerra a execução
     */
    function verificaErros()
    {
        if (!empty($this->erros)) {
            $this->conteudo = [
                'status' => 'error',
                'errors' => $this->erros,
                'reply' => "Desculpe, não entendi sua mensagem. Digite *AJUDA* para ver as opções."
            ];

            $this->encerra();
        }
    }

    /**
     * Encerra a execução do script e retorna a resposta JSON
     */
    public function encerra()
    {
        header('Content-Type: application/json');
        echo json_encode($this->conteudo);
        exit;
    }

    /**
     * Retorna a resposta para o usuário
     */
    public function responde()
    {
        $this->conteudo = [
            'status' => 'success',
            'reply' => $this->resposta,
        ];

        $this->encerra();
    }

    // Utilitários
    public function arquivoConversa()
    {
        return STORE_PATH . "/" . preg_replace('/[^a-z0-9]/i', '_', $this->usuario) . ".json";
    }

    public function obtemConversa()
    {
        $this->conversa = null;

        $arquivo = $this->arquivoConversa();

        if (file_exists($arquivo)) {
            $this->conversa = json_decode(file_get_contents($arquivo), false);
        }

    }

    public function salvaConversa($hash, $variaveis = [])
    {
        // Salva o histórico da conversa
        if (is_object($this->conversa)) {
            $this->conversa->hash = $hash;
        } else {
            $this->conversa = (object) [
                'hash' => $hash
            ];
        }

        if (!empty($variaveis)) {
            foreach ($variaveis as $key => $value) {
                $this->conversa->{$key} = $value;
            }
        }

        file_put_contents($this->arquivoConversa(), json_encode($this->conversa));
    }

    public function deletaConversa()
    {
        $arquivo = $this->arquivoConversa();

        if (file_exists($arquivo)) {
            unlink($arquivo);
        }
    }

    public function obtemFluxo($hash = false)
    {
        $hash = ($hash) ? $hash : $this->conversa->hash;

        if (empty($hash)) {
            $this->deletaConversa();
            return $this->checaHistorico();
        }

        $chats = array_values($this->scripts);

        foreach ($chats as $chat) {
            if ($this->_encontraFluxo($chat, $hash)) {
                return $this->_encontraFluxo($chat, $hash);
            }
        }

        return false;
    }

    function _encontraFluxo($chat, $hash)
    {
        if (!is_object($chat)) {
            return false;
        }

        if ($chat->hash == $hash) {
            return $chat;
        }

        if (isset($chat->proximo)) {
            return $this->_encontraFluxo($chat->proximo, $hash);
        }

        if (isset($chat->opcoes)) {
            foreach ($chat->opcoes as $opcao) {
                if ($this->_encontraFluxo($opcao, $hash)) {
                    return $this->_encontraFluxo($opcao, $hash);
                }
            }
        }

        return false;
    }

    function _fluxoApi()
    {
        $url = $this->_processaFrase($this->fluxo->proximo->url);
        $API = json_decode(file_get_contents($url), true);

        $variaveis = (array) $this->fluxo->proximo->resultado;
        $novas = [];
        if (!empty($API)) {
            foreach ($variaveis as $variavel => $ApiParams) {
                if (isset($API[$ApiParams])) {
                    $novas[$variavel] = $API[$ApiParams];
                }
            }
        }

        $this->salvaConversa($this->fluxo->proximo->hash, $novas);

        $this->fluxo = $this->obtemFluxo($this->fluxo->proximo->hash);

        $this->_criaTextoResposta($this->fluxo);

        // Zera o hash da conversa
        $this->salvaConversa("");
    }

    function _fluxoEscolha()
    {
        $index = intval($this->mensagem) - 1;
        $opcoes = $this->fluxo->opcoes;

        // Se a opção não existir, retorna uma mensagem de erro
        if (!isset($opcoes[$index])) {
            $this->resposta = 'A opção escolhida não existe. Por favor, escolha uma opção válida!';
            return;
        }

        $this->alternativa = $opcoes[$index];

        // Salva a conversa com a opção escolhida
        $this->salvaConversa($this->alternativa->hash);

        // Cria o conteúdo da resposta usando o texto do fluxo
        $this->_criaTextoResposta($this->alternativa);

    }

    function _fluxoPergunta()
    {
        // Salva a resposta do usuário como uma variável
        $variavel = [
            $this->fluxo->variavel => $this->mensagem
        ];

        if ($this->entrada) {
            // Salva a conversa usando a hash atual
            $this->salvaConversa($this->fluxo->hash);

            // Cria o conteúdo da resposta usando o texto do fluxo
            $this->_criaTextoResposta($this->fluxo);

        } else {
            // Salva a conversa usando a hash do proximo fluxo
            $this->salvaConversa($this->fluxo->proximo->hash, $variavel);

            // Cria o conteúdo da resposta usando o texto do proximo fluxo
            $this->_criaTextoResposta($this->fluxo->proximo);
        }


    }

    function _processaFrase($texto)
    {
        $variaveis = (array) $this->conversa;

        return trim(
            preg_replace_callback(
                '#{(.*?)}#',
                function ($match) use ($variaveis) {
                    $match[1] = trim($match[1], '$');
                    return isset($variaveis[$match[1]]) ? $variaveis[$match[1]] : "---";
                },
                " " . $texto . " "
            )
        );
    }


    function _criaTextoResposta($fluxo)
    {
        $tipo = $fluxo->tipo;

        // Tipo de resposta padrão
        if (in_array($tipo, ['pergunta', 'flow', 'final', 'api'])) {
            $this->resposta .= $this->_processaFrase($fluxo->mensagem) . "\n";
        }

        // Tipo de resposta de multiplas opções
        if ($tipo == 'escolha') {
            $this->resposta .= $this->_processaFrase($fluxo->mensagem) . "\n";

            $i = 1;
            foreach ($fluxo->opcoes as $opcao) {
                $this->resposta .= "*$i –* " . $this->_processaFrase($opcao->escolha) . "\n";
                $i++;
            }
        }

        // Verifica se preenchemos uma resposta
        if (empty($this->resposta)) {
            $this->erros[] = 'Não foi possível criar uma resposta para o fluxo: ' . $fluxo->hash;
        }

        // Verifica se houve erros
        $this->verificaErros();
    }

    public function debuga($string)
    {
        $arquivo = __DIR__ . '/log.txt';
        $conteudo = $string . "\n";
        file_put_contents($arquivo, $conteudo, FILE_APPEND);
    }

}