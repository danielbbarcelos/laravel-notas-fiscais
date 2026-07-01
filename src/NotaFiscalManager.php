<?php

declare(strict_types=1);

namespace DanielBBarcelos\NotasFiscais;

use Closure;
use DanielBBarcelos\NotasFiscais\Contracts\NfseGateway;
use DanielBBarcelos\NotasFiscais\Contracts\Provedor;
use DanielBBarcelos\NotasFiscais\Drivers\Ipm\IpmProvedor;
use DanielBBarcelos\NotasFiscais\Drivers\IpmAbrasf\IpmAbrasfProvedor;
use DanielBBarcelos\NotasFiscais\Exceptions\NotaFiscalException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolve e cacheia provedores de nota fiscal a partir de config/notas-fiscais.php.
 * Segue o espírito do Illuminate\Support\Manager, com "conexões nomeadas" (cada
 * entrada de "drivers" é uma instância configurada que aponta para um tipo de
 * driver), como faz o database/mail. Terceiros registram drivers via extend().
 */
class NotaFiscalManager
{
    /** @var array<string, Provedor> Instâncias já resolvidas, por nome de conexão. */
    protected array $provedores = [];

    /** @var array<string, Closure(array<string,mixed>, string): Provedor> */
    protected array $criadores = [];

    public function __construct(protected Container $app)
    {
        $this->registrarDriversPadrao();
    }

    /**
     * Resolve um provedor pelo nome de conexão (ou o padrão da config).
     *
     * Passando $overrides, a config nomeada vira apenas a base e os campos
     * informados são sobrepostos em runtime — útil para multi-tenant (SaaS),
     * onde cada empresa traz suas próprias credenciais. Nesse caso a instância
     * NÃO é cacheada pelo nome, evitando vazamento de credenciais entre tenants.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function provedor(?string $nome = null, array $overrides = []): Provedor
    {
        $nome ??= $this->provedorPadrao();

        if ($overrides !== []) {
            $base = $this->configExiste($nome) ? $this->configDe($nome) : ['driver' => $nome];

            return $this->build(array_merge($base, $overrides), $nome);
        }

        return $this->provedores[$nome] ??= $this->resolver($nome);
    }

    /** Alias expressivo: NotaFiscal::driver('ipm') ou driver('ipm', $overrides). */
    public function driver(?string $nome = null, array $overrides = []): Provedor
    {
        return $this->provedor($nome, $overrides);
    }

    /**
     * Constrói um provedor a partir de uma configuração informada em runtime,
     * sem registrá-la nem cacheá-la pelo nome. Caminho recomendado para SaaS:
     * a aplicação guarda as credenciais do tenant com segurança e injeta aqui.
     *
     * A config deve conter a chave 'driver' (ex.: 'ipm').
     *
     * @param  array<string, mixed>  $config
     */
    public function build(array $config, ?string $nome = null): Provedor
    {
        $tipo = $config['driver'] ?? null;

        if ($tipo === null) {
            throw new NotaFiscalException("A configuração informada não define a chave 'driver'.");
        }

        if (! isset($this->criadores[$tipo])) {
            throw new NotaFiscalException("Driver de nota fiscal [{$tipo}] não registrado.");
        }

        return ($this->criadores[$tipo])($config, $nome ?? (string) $tipo);
    }

    /** Registra um driver customizado (tipo => fábrica). */
    public function extend(string $driver, Closure $factory): static
    {
        $this->criadores[$driver] = $factory;

        return $this;
    }

    public function provedorPadrao(): string
    {
        return (string) $this->app['config']->get('notas-fiscais.default');
    }

    /** Atalho para a NFS-e do provedor padrão: NotaFiscal::nfse()->emitir(...). */
    public function nfse(): NfseGateway
    {
        return $this->provedor()->nfse();
    }

    protected function resolver(string $nome): Provedor
    {
        $config = $this->configDe($nome);

        if (! isset($config['driver'])) {
            throw new NotaFiscalException("A conexão de provedor [{$nome}] não define a chave 'driver'.");
        }

        return $this->build($config, $nome);
    }

    /** @return array<string, mixed> */
    protected function configDe(string $nome): array
    {
        $config = $this->app['config']->get("notas-fiscais.drivers.{$nome}");

        if (! is_array($config)) {
            throw new NotaFiscalException("Provedor [{$nome}] não configurado em notas-fiscais.drivers.");
        }

        return $config;
    }

    protected function configExiste(string $nome): bool
    {
        return is_array($this->app['config']->get("notas-fiscais.drivers.{$nome}"));
    }

    protected function registrarDriversPadrao(): void
    {
        // Driver REST proprietário (NTE 35/2021, layout <nfse>, multipart).
        $this->extend('ipm', fn (array $config, string $nome) => new IpmProvedor($config, $nome));

        // Driver ABRASF 2.04 (SOAP, WNENotaFiscalEletronicaNfe) — ex.: Pouso Alegre/MG.
        $this->extend('ipm-abrasf', fn (array $config, string $nome) => new IpmAbrasfProvedor($config, $nome));
    }
}
