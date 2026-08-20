<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI - Gestionnaire Libre de Parc Informatique
 *
 * http://glpi-project.org
 *
 * @copyright 2015-2026 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of GLPI.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\Tools\Command;

use CommonDBTM;
use CommonDropdown;
use Computer;
use ComputerModel;
use ComputerType;
use Contact;
use Contact_Supplier;
use Contract;
use Contract_Supplier;
use DateInterval;
use Entity;
use Glpi\Application\Environment;
use Glpi\Asset\Asset_PeripheralAsset;
use Glpi\Console\AbstractCommand;
use Group;
use Group_User;
use Item_SoftwareVersion;
use ITILCategory;
use ITILFollowup;
use ITILSolution;
use KnowbaseItem;
use KnowbaseItemCategory;
use Location;
use Manufacturer;
use Monitor;
use MonitorModel;
use MonitorType;
use NetworkEquipment;
use NetworkEquipmentModel;
use NetworkEquipmentType;
use OperatingSystem;
use Override;
use Phone;
use PhoneModel;
use PhoneType;
use Printer;
use PrinterModel;
use PrinterType;
use Profile_User;
use Project;
use ProjectState;
use ProjectTask;
use ProjectType;
use RuntimeException;
use Safe\DateTimeImmutable;
use Session;
use Software;
use SoftwareCategory;
use SoftwareVersion;
use SolutionType;
use State;
use Supplier;
use SupplierType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TaskCategory;
use Ticket;
use TicketTask;
use User;

use function Safe\json_encode;

/**
 * Populates a *local* GLPI instance with a coherent set of demo data.
 *
 * This is a development tool: it is refused outside of the development environment unless
 * `--force` is passed, and it is never shipped to a production install (the `Glpi\Tools\`
 * namespace lives in `autoload-dev`).
 *
 * Everything it writes goes through the regular GLPI objects (`CommonDBTM::add()`), so trees,
 * caches, history and business rules behave exactly like a creation made from the UI.
 *
 * The command is idempotent: every record is looked up by its natural key before being created,
 * so running it twice does not duplicate anything. Random values are drawn from a seeded PRNG,
 * so a full run always produces the same dataset; a partial run (`--only`) draws a different
 * stream, which only changes the fields that are not part of a natural key.
 *
 * Usage:
 *   bin/console tools:seed
 *   bin/console tools:seed --scale=5
 *   bin/console tools:seed --only=dropdowns,assets
 *   bin/console tools:seed --list
 */
final class SeedCommand extends AbstractCommand
{
    /**
     * Fixed seed of the PRNG, so two runs on the same database produce the same data.
     */
    private const DEFAULT_RANDOM_SEED = 20260820;

    protected $requires_db_up_to_date = true;

    private SymfonyStyle $io;

    /**
     * Volume multiplier, from `--scale`.
     */
    private int $scale = 1;

    /**
     * Ids of the records handled during this run, per itemtype.
     *
     * Both created and pre-existing records land here, so a group can always reference what
     * the groups it depends on produced.
     *
     * @var array<class-string<CommonDBTM>, list<int>>
     */
    private array $ids = [];

    /**
     * Per-itemtype counters, `[created, reused]`.
     *
     * @var array<class-string<CommonDBTM>, array{0: int, 1: int}>
     */
    private array $stats = [];

    /**
     * Position of the {@see self::rotate()} window.
     */
    private int $rotation_cursor = 0;

    #[Override]
    protected function configure(): void
    {
        parent::configure();

        $this->setName('tools:seed');
        $this->setDescription('Populate a local instance with demo data (development only).');

        $this->addOption(
            'scale',
            's',
            InputOption::VALUE_REQUIRED,
            'Volume multiplier applied to the generated records.',
            '1'
        );
        $this->addOption(
            'only',
            'o',
            InputOption::VALUE_REQUIRED,
            'Comma separated list of groups to seed (dependencies are pulled in automatically).'
        );
        $this->addOption(
            'except',
            'x',
            InputOption::VALUE_REQUIRED,
            'Comma separated list of groups to skip.'
        );
        $this->addOption(
            'username',
            'u',
            InputOption::VALUE_REQUIRED,
            'GLPI user the records are created as.',
            'glpi'
        );
        $this->addOption(
            'random-seed',
            null,
            InputOption::VALUE_REQUIRED,
            'PRNG seed, change it to get a different dataset.',
            (string) self::DEFAULT_RANDOM_SEED
        );
        $this->addOption(
            'list',
            'l',
            InputOption::VALUE_NONE,
            'List the available groups and exit.'
        );
        $this->addOption(
            'force',
            'f',
            InputOption::VALUE_NONE,
            'Run even outside of the development environment.'
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var array<string, mixed> $CFG_GLPI */
        global $CFG_GLPI;

        $this->io = new SymfonyStyle($input, $output);

        if ($input->getOption('list')) {
            $this->listGroups();
            return Command::SUCCESS;
        }

        $environment = Environment::get();
        if ($environment !== Environment::DEVELOPMENT && !$input->getOption('force')) {
            $this->io->error([
                sprintf('Current environment is "%s".', $environment->value),
                'This command writes demo data and is only meant for a local development instance.',
                'Use --force if you really know what you are doing.',
            ]);
            return Command::FAILURE;
        }

        $this->scale = max(1, (int) $input->getOption('scale'));
        mt_srand((int) $input->getOption('random-seed'));

        // The seed creates hundreds of objects; queuing a notification for each of them is both
        // slow and pointless on a local instance.
        $CFG_GLPI['use_notifications'] = 0;

        $this->loadUserSession((string) $input->getOption('username'));
        Session::changeProfile(4); // Super-Admin
        Session::changeActiveEntities('all');

        $groups = $this->resolveGroups(
            $this->parseList($input->getOption('only')),
            $this->parseList($input->getOption('except'))
        );
        if ($groups === []) {
            $this->io->warning('Nothing to seed.');
            return Command::SUCCESS;
        }

        $seeders = $this->getSeeders();
        foreach ($groups as $group) {
            $this->io->section($group);
            $seeders[$group]();
        }

        $this->outputStats();

        return Command::SUCCESS;
    }

    // -----------------------------------------------------------------------------------------
    // Group registry
    // -----------------------------------------------------------------------------------------

    /**
     * The seeders, in the order they must run.
     *
     * Adding a group to the seed means: write a `seedXxx()` method, register it here, and declare
     * what it needs in {@see self::getDependencies()}.
     *
     * @return array<string, callable(): void>
     */
    private function getSeeders(): array
    {
        return [
            'entities'  => $this->seedEntities(...),
            'dropdowns' => $this->seedDropdowns(...),
            'users'     => $this->seedUsers(...),
            'suppliers' => $this->seedSuppliers(...),
            'assets'    => $this->seedAssets(...),
            'software'  => $this->seedSoftware(...),
            'tickets'   => $this->seedTickets(...),
            'projects'  => $this->seedProjects(...),
            'knowbase'  => $this->seedKnowbase(...),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function getDependencies(): array
    {
        return [
            'entities'  => [],
            'dropdowns' => ['entities'],
            'users'     => ['entities'],
            'suppliers' => ['entities', 'dropdowns'],
            'assets'    => ['entities', 'dropdowns', 'users'],
            'software'  => ['entities', 'dropdowns', 'assets'],
            'tickets'   => ['entities', 'dropdowns', 'users', 'assets'],
            'projects'  => ['entities', 'users'],
            'knowbase'  => ['entities', 'users'],
        ];
    }

    private function listGroups(): void
    {
        $dependencies = $this->getDependencies();

        $rows = [];
        foreach (array_keys($this->getSeeders()) as $group) {
            $rows[] = [$group, implode(', ', $dependencies[$group]) ?: '-'];
        }

        $this->io->table(['Group', 'Depends on'], $rows);
    }

    /**
     * Expand the requested groups with their dependencies, keeping the declaration order.
     *
     * @param list<string> $only
     * @param list<string> $except
     *
     * @return list<string>
     */
    private function resolveGroups(array $only, array $except): array
    {
        $all = array_keys($this->getSeeders());

        $unknown = array_diff([...$only, ...$except], $all);
        if ($unknown !== []) {
            throw new RuntimeException(
                sprintf('Unknown group(s): %s. Known groups: %s.', implode(', ', $unknown), implode(', ', $all))
            );
        }

        $requested = $only === [] ? $all : $this->withDependencies($only);

        return array_values(array_filter(
            $all,
            static fn(string $group): bool => in_array($group, $requested, true)
                && !in_array($group, $except, true)
        ));
    }

    /**
     * @param list<string> $groups
     *
     * @return list<string>
     */
    private function withDependencies(array $groups): array
    {
        $dependencies = $this->getDependencies();

        $resolved = [];
        $queue = $groups;
        while ($queue !== []) {
            $group = array_shift($queue);
            if (in_array($group, $resolved, true)) {
                continue;
            }
            $resolved[] = $group;
            $queue = [...$queue, ...$dependencies[$group]];
        }

        return $resolved;
    }

    // -----------------------------------------------------------------------------------------
    // Seeders
    // -----------------------------------------------------------------------------------------

    private function seedEntities(): void
    {
        $root = 0;

        $sede = $this->ensureTree(Entity::class, [
            'name'        => 'Sede',
            'entities_id' => $root,
            'comment'     => 'Entidade principal (dados de demonstração).',
        ]);

        foreach (['Filial Norte', 'Filial Sul'] as $branch) {
            $this->ensureTree(Entity::class, [
                'name'        => $branch,
                'entities_id' => $sede,
            ]);
        }

        foreach (['TI', 'Administrativo', 'Atendimento'] as $department) {
            $this->ensureTree(Entity::class, [
                'name'        => $department,
                'entities_id' => $sede,
            ]);
        }
    }

    private function seedDropdowns(): void
    {
        $entity = $this->mainEntity();

        $this->seedTree(Location::class, $entity, [
            'Prédio Central' => ['Térreo', '1º andar', '2º andar'],
            'Anexo'          => ['Almoxarifado', 'Datacenter'],
        ]);

        $this->seedTree(ITILCategory::class, $entity, [
            'Infraestrutura' => ['Rede', 'Servidores', 'Telefonia'],
            'Suporte'        => ['Hardware', 'Software', 'Impressão'],
            'Acessos'        => ['Criação de conta', 'Redefinição de senha', 'Permissões'],
        ]);

        $this->seedTree(TaskCategory::class, $entity, [
            'Atendimento' => ['Diagnóstico', 'Execução', 'Validação'],
        ]);

        $this->seedTree(State::class, $entity, [
            'Em produção' => ['Em uso', 'Em estoque'],
            'Fora de uso' => ['Em manutenção', 'Descartado'],
        ]);

        $flat = [
            Manufacturer::class          => ['Dell', 'Lenovo', 'HP', 'Positivo', 'Intelbras'],
            ComputerType::class          => ['Desktop', 'Notebook', 'Servidor'],
            ComputerModel::class         => ['OptiPlex 3080', 'ThinkCentre M70', 'ProDesk 400'],
            MonitorType::class           => ['LED 21"', 'LED 24"'],
            MonitorModel::class          => ['E2216H', 'ThinkVision T22'],
            PrinterType::class           => ['Laser', 'Multifuncional'],
            PrinterModel::class          => ['LaserJet Pro M404', 'ECOSYS M2040'],
            PhoneType::class             => ['IP', 'Analógico'],
            PhoneModel::class            => ['TIP 235 G', 'GXP1625'],
            NetworkEquipmentType::class  => ['Switch', 'Roteador', 'Firewall'],
            NetworkEquipmentModel::class => ['SG 2404 MR', 'Catalyst 2960'],
            OperatingSystem::class       => ['Windows 11', 'Ubuntu 24.04', 'Debian 12'],
            SoftwareCategory::class      => ['Escritório', 'Segurança', 'Desenvolvimento'],
            SolutionType::class          => ['Resolvido remotamente', 'Resolvido presencialmente', 'Orientação ao usuário'],
            SupplierType::class          => ['Fornecedor de equipamentos', 'Prestador de serviços'],
            ProjectType::class           => ['Interno', 'Contratado'],
            ProjectState::class          => ['Planejado', 'Em andamento', 'Concluído'],
            KnowbaseItemCategory::class  => ['Procedimentos', 'Perguntas frequentes'],
        ];
        foreach ($flat as $itemtype => $names) {
            foreach ($names as $name) {
                $this->ensureDropdown($itemtype, $this->withEntity($itemtype, ['name' => $name], $entity));
            }
        }
    }

    private function seedUsers(): void
    {
        $entity = $this->mainEntity();

        $groups = [
            'Suporte N1'      => ['is_requester' => 1, 'is_assign' => 1],
            'Suporte N2'      => ['is_requester' => 0, 'is_assign' => 1],
            'Administrativo'  => ['is_requester' => 1, 'is_assign' => 0],
        ];
        foreach ($groups as $name => $flags) {
            $this->ensure(Group::class, ['name' => $name, 'entities_id' => $entity], [
                'name'         => $name,
                'entities_id'  => $entity,
                'is_recursive' => 1,
                ...$flags,
            ]);
        }

        // profiles_id: 1 = Self-Service, 2 = Observer, 4 = Super-Admin, 6 = Technician.
        $people = [
            ['ana.souza', 'Ana', 'Souza', 6],
            ['bruno.lima', 'Bruno', 'Lima', 6],
            ['carla.mendes', 'Carla', 'Mendes', 2],
            ['diego.rocha', 'Diego', 'Rocha', 1],
            ['elisa.barros', 'Elisa', 'Barros', 1],
            ['fabio.nunes', 'Fábio', 'Nunes', 1],
        ];
        $extra = $this->scale - 1;
        for ($i = 1; $i <= $extra * 4; $i++) {
            $people[] = [sprintf('usuario.demo%02d', $i), 'Usuário', sprintf('Demo %02d', $i), 1];
        }

        foreach ($people as $index => [$login, $firstname, $realname, $profile]) {
            $users_id = $this->ensure(User::class, ['name' => $login], [
                'name'       => $login,
                'realname'   => $realname,
                'firstname'  => $firstname,
                'password'   => 'seed1234',
                'password2'  => 'seed1234',
                'is_active'  => 1,
                '_useremails' => [-1 => sprintf('%s@example.localhost', $login)],
            ]);

            $this->ensure(
                Profile_User::class,
                ['users_id' => $users_id, 'profiles_id' => $profile, 'entities_id' => $entity],
                [
                    'users_id'     => $users_id,
                    'profiles_id'  => $profile,
                    'entities_id'  => $entity,
                    'is_recursive' => 1,
                ]
            );

            // Round-robin instead of a random pick: the membership stays the same on a re-run,
            // even a partial one, so nobody ends up in every group after a few passes.
            $groups_id = $this->ids[Group::class][$index % count($this->ids[Group::class])];
            $this->ensure(
                Group_User::class,
                ['users_id' => $users_id, 'groups_id' => $groups_id],
                ['users_id' => $users_id, 'groups_id' => $groups_id]
            );
        }
    }

    private function seedSuppliers(): void
    {
        $entity = $this->mainEntity();

        $suppliers = ['Tec Suprimentos', 'Rede & Cia', 'Suporte Total'];
        foreach ($suppliers as $index => $name) {
            $suppliers_id = $this->ensure(Supplier::class, ['name' => $name, 'entities_id' => $entity], [
                'name'             => $name,
                'entities_id'      => $entity,
                'is_recursive'     => 1,
                'suppliertypes_id' => $this->pick($this->ids[SupplierType::class]),
                'is_active'        => 1,
                'email'            => sprintf('contato%d@example.localhost', $index + 1),
                'phonenumber'      => sprintf('(95) 3224-00%02d', $index + 1),
            ]);

            $contact_name = sprintf('Contato %s', $name);
            $contacts_id = $this->ensure(
                Contact::class,
                ['name' => $contact_name, 'entities_id' => $entity],
                [
                    'name'        => $contact_name,
                    'firstname'   => 'Responsável',
                    'entities_id' => $entity,
                    'email'       => sprintf('resp%d@example.localhost', $index + 1),
                ]
            );
            $this->ensure(
                Contact_Supplier::class,
                ['contacts_id' => $contacts_id, 'suppliers_id' => $suppliers_id],
                ['contacts_id' => $contacts_id, 'suppliers_id' => $suppliers_id]
            );

            $contract_name = sprintf('Contrato %s', $name);
            $contracts_id = $this->ensure(
                Contract::class,
                ['name' => $contract_name, 'entities_id' => $entity],
                [
                    'name'         => $contract_name,
                    'entities_id'  => $entity,
                    'is_recursive' => 1,
                    'num'          => sprintf('CT-%04d', $index + 1),
                    'begin_date'   => $this->daysAgo(180 + $index * 30)->format('Y-m-d'),
                    'duration'     => 24,
                    'notice'       => 2,
                ]
            );
            $this->ensure(
                Contract_Supplier::class,
                ['contracts_id' => $contracts_id, 'suppliers_id' => $suppliers_id],
                ['contracts_id' => $contracts_id, 'suppliers_id' => $suppliers_id]
            );
        }
    }

    private function seedAssets(): void
    {
        $entity = $this->mainEntity();

        $assets = [
            Computer::class => [
                'count'  => 8,
                'prefix' => 'PC',
                'fields' => [
                    'computertypes_id'  => ComputerType::class,
                    'computermodels_id' => ComputerModel::class,
                ],
            ],
            Monitor::class => [
                'count'  => 6,
                'prefix' => 'MON',
                'fields' => [
                    'monitortypes_id'  => MonitorType::class,
                    'monitormodels_id' => MonitorModel::class,
                ],
            ],
            Printer::class => [
                'count'  => 3,
                'prefix' => 'IMP',
                'fields' => [
                    'printertypes_id'  => PrinterType::class,
                    'printermodels_id' => PrinterModel::class,
                ],
            ],
            Phone::class => [
                'count'  => 4,
                'prefix' => 'TEL',
                'fields' => [
                    'phonetypes_id'  => PhoneType::class,
                    'phonemodels_id' => PhoneModel::class,
                ],
            ],
            NetworkEquipment::class => [
                'count'  => 3,
                'prefix' => 'NET',
                'fields' => [
                    'networkequipmenttypes_id'  => NetworkEquipmentType::class,
                    'networkequipmentmodels_id' => NetworkEquipmentModel::class,
                ],
            ],
        ];

        foreach ($assets as $itemtype => $definition) {
            $total = $definition['count'] * $this->scale;
            for ($i = 1; $i <= $total; $i++) {
                $name = sprintf('%s-%03d', $definition['prefix'], $i);

                $input = [
                    'name'             => $name,
                    'entities_id'      => $entity,
                    'serial'           => sprintf('SN%s%04d', $definition['prefix'], $i),
                    'otherserial'      => sprintf('PAT-%s-%04d', $definition['prefix'], $i),
                    'locations_id'     => $this->pick($this->ids[Location::class]),
                    'states_id'        => $this->pick($this->ids[State::class]),
                    'manufacturers_id' => $this->pick($this->ids[Manufacturer::class]),
                    'users_id'         => $this->pick($this->ids[User::class]),
                    'groups_id'        => $this->pick($this->ids[Group::class]),
                    'comment'          => 'Ativo de demonstração.',
                ];
                foreach ($definition['fields'] as $field => $dropdown) {
                    $input[$field] = $this->pick($this->ids[$dropdown]);
                }

                $this->ensure($itemtype, ['name' => $name, 'entities_id' => $entity], $input);
            }
        }

        $this->connectPeripherals();
    }

    /**
     * Plug each monitor/printer/phone into a computer, so the "connections" tab is not empty.
     */
    private function connectPeripherals(): void
    {
        foreach ([Monitor::class, Printer::class, Phone::class] as $itemtype) {
            foreach ($this->ids[$itemtype] as $items_id) {
                // A peripheral hangs on a single asset, so it alone is the natural key here:
                // matching on the pair would try to plug an already connected monitor elsewhere.
                $this->ensure(
                    Asset_PeripheralAsset::class,
                    [
                        'itemtype_peripheral' => $itemtype,
                        'items_id_peripheral' => $items_id,
                    ],
                    [
                        'itemtype_asset'      => Computer::class,
                        'items_id_asset'      => $this->pick($this->ids[Computer::class]),
                        'itemtype_peripheral' => $itemtype,
                        'items_id_peripheral' => $items_id,
                    ]
                );
            }
        }
    }

    private function seedSoftware(): void
    {
        $entity = $this->mainEntity();

        $catalog = [
            'LibreOffice'    => ['24.2', '25.2'],
            'Firefox ESR'    => ['128', '140'],
            'Antivírus Corp' => ['10.1'],
            'Cliente VPN'    => ['3.0', '3.2'],
        ];

        foreach ($catalog as $name => $versions) {
            $softwares_id = $this->ensure(Software::class, ['name' => $name, 'entities_id' => $entity], [
                'name'                 => $name,
                'entities_id'          => $entity,
                'is_recursive'         => 1,
                'manufacturers_id'     => $this->pick($this->ids[Manufacturer::class]),
                'softwarecategories_id' => $this->pick($this->ids[SoftwareCategory::class]),
            ]);

            foreach ($versions as $version) {
                $versions_id = $this->ensure(
                    SoftwareVersion::class,
                    ['name' => $version, 'softwares_id' => $softwares_id],
                    [
                        'name'                => $version,
                        'softwares_id'        => $softwares_id,
                        'entities_id'         => $entity,
                        'operatingsystems_id' => $this->pick($this->ids[OperatingSystem::class]),
                    ]
                );

                foreach ($this->rotate($this->ids[Computer::class], 3 * $this->scale) as $computers_id) {
                    $this->ensure(
                        Item_SoftwareVersion::class,
                        [
                            'itemtype'            => Computer::class,
                            'items_id'            => $computers_id,
                            'softwareversions_id' => $versions_id,
                        ],
                        [
                            'itemtype'            => Computer::class,
                            'items_id'            => $computers_id,
                            'softwareversions_id' => $versions_id,
                            'entities_id'         => $entity,
                            'date_install'        => $this->daysAgo(mt_rand(10, 400))->format('Y-m-d'),
                        ]
                    );
                }
            }
        }
    }

    private function seedTickets(): void
    {
        $entity = $this->mainEntity();

        $subjects = [
            'Computador não liga',
            'Impressora sem conexão de rede',
            'Solicitação de acesso ao sistema',
            'Lentidão no acesso à rede',
            'Redefinição de senha',
            'Instalação de software',
            'Ramal sem áudio',
            'Troca de monitor com defeito',
            'Erro ao abrir planilha compartilhada',
            'Cadastro de novo usuário',
            'Backup não concluído',
            'Certificado digital expirado',
        ];

        $total = count($subjects) * $this->scale;
        for ($i = 1; $i <= $total; $i++) {
            $name = sprintf('%s (demo %03d)', $subjects[($i - 1) % count($subjects)], $i);
            $opened_days_ago = mt_rand(0, 90);

            $tickets_id = $this->ensure(Ticket::class, ['name' => $name, 'entities_id' => $entity], [
                'name'                 => $name,
                'content'              => sprintf(
                    '<p>Chamado de demonstração gerado pelo seed.</p><p>Descrição: %s.</p>',
                    $subjects[($i - 1) % count($subjects)]
                ),
                'entities_id'          => $entity,
                'type'                 => $this->pick([Ticket::INCIDENT_TYPE, Ticket::DEMAND_TYPE]),
                'itilcategories_id'    => $this->pick($this->ids[ITILCategory::class]),
                'urgency'              => mt_rand(1, 5),
                'impact'               => mt_rand(1, 5),
                'date'                 => $this->daysAgo($opened_days_ago)->format('Y-m-d H:i:s'),
                '_users_id_requester'  => $this->pick($this->ids[User::class]),
                '_users_id_assign'     => $this->pick($this->ids[User::class]),
                '_groups_id_assign'    => $this->pick($this->ids[Group::class]),
                'locations_id'         => $this->pick($this->ids[Location::class]),
                '_actors'              => [],
            ]);

            $this->addTicketHistory($tickets_id, $i, $opened_days_ago);
        }
    }

    /**
     * Give a ticket a plausible life: a followup, a task, and a solution on part of them.
     */
    private function addTicketHistory(int $tickets_id, int $index, int $opened_days_ago): void
    {
        $this->ensure(
            ITILFollowup::class,
            ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
            [
                'itemtype'    => Ticket::class,
                'items_id'    => $tickets_id,
                'content'     => '<p>Entramos em contato com o solicitante para coletar mais detalhes.</p>',
                'users_id'    => $this->pick($this->ids[User::class]),
                'date'        => $this->daysAgo(max(0, $opened_days_ago - 1))->format('Y-m-d H:i:s'),
                'is_private'  => 0,
            ]
        );

        $this->ensure(
            TicketTask::class,
            ['tickets_id' => $tickets_id],
            [
                'tickets_id'       => $tickets_id,
                'content'          => '<p>Diagnóstico realizado no equipamento.</p>',
                'taskcategories_id' => $this->pick($this->ids[TaskCategory::class]),
                'actiontime'       => 60 * mt_rand(15, 120),
                'state'            => 2, // Done
                'users_id'         => $this->pick($this->ids[User::class]),
            ]
        );

        // Two thirds of the tickets get solved, so dashboards have both open and closed data.
        if ($index % 3 === 0) {
            return;
        }

        $this->ensure(
            ITILSolution::class,
            ['itemtype' => Ticket::class, 'items_id' => $tickets_id],
            [
                'itemtype'         => Ticket::class,
                'items_id'         => $tickets_id,
                'content'          => '<p>Atendimento concluído e validado com o solicitante.</p>',
                'solutiontypes_id' => $this->pick($this->ids[SolutionType::class]),
                'users_id'         => $this->pick($this->ids[User::class]),
            ]
        );
    }

    private function seedProjects(): void
    {
        $entity = $this->mainEntity();

        $projects = [
            'Renovação do parque de computadores' => ['Levantamento', 'Compra', 'Implantação'],
            'Migração de servidores'              => ['Inventário', 'Testes', 'Cutover'],
            'Padronização de impressoras'         => ['Mapeamento', 'Substituição'],
        ];

        foreach ($projects as $name => $tasks) {
            $projects_id = $this->ensure(Project::class, ['name' => $name, 'entities_id' => $entity], [
                'name'             => $name,
                'entities_id'      => $entity,
                'is_recursive'     => 1,
                'content'          => '<p>Projeto de demonstração gerado pelo seed.</p>',
                'priority'         => mt_rand(1, 5),
                'percent_done'     => mt_rand(0, 100),
                'projectstates_id' => $this->pick($this->ids[ProjectState::class]),
                'projecttypes_id'  => $this->pick($this->ids[ProjectType::class]),
                'users_id'         => $this->pick($this->ids[User::class]),
                'groups_id'        => $this->pick($this->ids[Group::class]),
                'plan_start_date'  => $this->daysAgo(60)->format('Y-m-d H:i:s'),
                'plan_end_date'    => $this->daysAgo(-60)->format('Y-m-d H:i:s'),
            ]);

            foreach ($tasks as $position => $task) {
                $this->ensure(
                    ProjectTask::class,
                    ['name' => $task, 'projects_id' => $projects_id],
                    [
                        'name'             => $task,
                        'projects_id'      => $projects_id,
                        'entities_id'      => $entity,
                        'content'          => '<p>Etapa de demonstração.</p>',
                        'projectstates_id' => $this->pick($this->ids[ProjectState::class]),
                        'percent_done'     => mt_rand(0, 100),
                        'users_id'         => $this->pick($this->ids[User::class]),
                        'plan_start_date'  => $this->daysAgo(60 - $position * 15)->format('Y-m-d H:i:s'),
                        'plan_end_date'    => $this->daysAgo(45 - $position * 15)->format('Y-m-d H:i:s'),
                    ]
                );
            }
        }
    }

    private function seedKnowbase(): void
    {
        $entity = $this->mainEntity();

        $categories = $this->ids[KnowbaseItemCategory::class]
            ?? [$this->ensureDropdown(KnowbaseItemCategory::class, ['name' => 'Procedimentos'])];

        $articles = [
            'Como redefinir a senha da rede' => 'Acesse o portal, clique em "Esqueci minha senha" e siga as instruções.',
            'Procedimento de abertura de chamado' => 'Descreva o problema, informe o patrimônio do equipamento e anexe evidências.',
            'Configuração de impressora de rede' => 'Adicione a impressora pelo endereço IP informado pela equipe de TI.',
        ];

        foreach ($articles as $title => $answer) {
            $this->ensure(KnowbaseItem::class, ['name' => $title], [
                'name'                      => $title,
                'answer'                    => sprintf('<p>%s</p>', $answer),
                'is_faq'                    => 1,
                'entities_id'               => $entity,
                'is_recursive'              => 1,
                'knowbaseitemcategories_id' => $this->pick($categories),
                'users_id'                  => $this->pick($this->ids[User::class] ?? [Session::getLoginUserID()]),
                '_visibility'               => [
                    '_type'        => 'Entity',
                    'entities_id'  => $entity,
                    'is_recursive' => 1,
                ],
            ]);
        }
    }

    // -----------------------------------------------------------------------------------------
    // Creation helpers
    // -----------------------------------------------------------------------------------------

    /**
     * Create the record described by `$input` unless one already matches `$criteria`.
     *
     * @param class-string<CommonDBTM> $itemtype
     * @param array<string, mixed>     $criteria Natural key used to detect an existing record.
     * @param array<string, mixed>     $input    Full input passed to `CommonDBTM::add()`.
     */
    private function ensure(string $itemtype, array $criteria, array $input): int
    {
        $item = $this->newItem($itemtype);

        if ($item->getFromDBByCrit($criteria)) {
            return $this->remember($itemtype, (int) $item->fields['id'], created: false);
        }

        $id = $item->add($input);
        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException(
                sprintf('Unable to create a "%s" (%s).', $itemtype, json_encode($criteria))
            );
        }

        return $this->remember($itemtype, $id, created: true);
    }

    /**
     * Create a dropdown value through `CommonDropdown::import()`, which is already find-or-create.
     *
     * @param class-string<CommonDropdown> $itemtype
     * @param array<string, mixed>         $input
     */
    private function ensureDropdown(string $itemtype, array $input): int
    {
        $item = $this->newItem($itemtype);
        if (!$item instanceof CommonDropdown) {
            throw new RuntimeException(sprintf('"%s" is not a dropdown.', $itemtype));
        }

        $existing = $item->findID($input);
        $id = $item->import($input);
        if (!is_int($id) || $id <= 0) {
            throw new RuntimeException(sprintf('Unable to import "%s" into "%s".', $input['name'], $itemtype));
        }

        return $this->remember($itemtype, $id, created: $existing <= 0);
    }

    /**
     * Same as {@see self::ensureDropdown()} for a tree dropdown: the parent drives the lookup, so
     * two branches may hold homonym children.
     *
     * @param class-string<CommonDropdown> $itemtype
     * @param array<string, mixed>         $input    Must carry the `<foreign key>` of the parent.
     */
    private function ensureTree(string $itemtype, array $input): int
    {
        $foreign_key = $itemtype::getForeignKeyField();
        $input[$foreign_key] ??= 0;

        $criteria = ['name' => $input['name'], $foreign_key => $input[$foreign_key]];
        if ($itemtype === Entity::class) {
            // Entities carry their parent in `entities_id`, which is also their own entity field.
            $criteria = ['name' => $input['name'], 'entities_id' => $input['entities_id']];
        }

        return $this->ensure($itemtype, $criteria, $input);
    }

    /**
     * Seed a two-level tree dropdown from a `parent => children` map.
     *
     * @param class-string<CommonDropdown> $itemtype
     * @param array<string, list<string>>  $tree
     */
    private function seedTree(string $itemtype, int $entities_id, array $tree): void
    {
        $foreign_key = $itemtype::getForeignKeyField();

        foreach ($tree as $parent => $children) {
            $parents_id = $this->ensureTree(
                $itemtype,
                $this->withEntity($itemtype, ['name' => $parent, $foreign_key => 0], $entities_id)
            );

            foreach ($children as $child) {
                $this->ensureTree(
                    $itemtype,
                    $this->withEntity($itemtype, ['name' => $child, $foreign_key => $parents_id], $entities_id)
                );
            }
        }
    }

    /**
     * Add the entity fields only to the itemtypes that actually have them.
     *
     * @param class-string<CommonDBTM> $itemtype
     * @param array<string, mixed>     $input
     *
     * @return array<string, mixed>
     */
    private function withEntity(string $itemtype, array $input, int $entities_id): array
    {
        $item = $this->newItem($itemtype);
        if (!$item->isEntityAssign()) {
            return $input;
        }

        $input['entities_id'] = $entities_id;
        if ($item->maybeRecursive()) {
            $input['is_recursive'] = 1;
        }

        return $input;
    }

    /**
     * @param class-string<CommonDBTM> $itemtype
     */
    private function newItem(string $itemtype): CommonDBTM
    {
        $item = getItemForItemtype($itemtype);
        if (!$item instanceof CommonDBTM) {
            throw new RuntimeException(sprintf('"%s" is not a valid itemtype.', $itemtype));
        }

        return $item;
    }

    /**
     * @param class-string<CommonDBTM> $itemtype
     */
    private function remember(string $itemtype, int $id, bool $created): int
    {
        if (!in_array($id, $this->ids[$itemtype] ?? [], true)) {
            $this->ids[$itemtype][] = $id;
        }

        $this->stats[$itemtype] ??= [0, 0];
        $this->stats[$itemtype][$created ? 0 : 1]++;

        return $id;
    }

    /**
     * Entity the demo data is attached to: the one the `entities` group creates, root otherwise.
     */
    private function mainEntity(): int
    {
        return $this->ids[Entity::class][0] ?? 0;
    }

    // -----------------------------------------------------------------------------------------
    // Small utilities
    // -----------------------------------------------------------------------------------------

    /**
     * @template T
     *
     * @param list<T> $values
     *
     * @return T
     */
    private function pick(array $values): mixed
    {
        if ($values === []) {
            throw new RuntimeException('Nothing to pick from: a dependency group did not run.');
        }

        return $values[mt_rand(0, count($values) - 1)];
    }

    /**
     * Take `$count` values, resuming where the previous call stopped and wrapping around.
     *
     * Deterministic on purpose: relations built on top of it (software installations, …) land on
     * the same items on every run, so a second pass reuses them instead of piling up new links.
     *
     * @template T
     *
     * @param list<T> $values
     *
     * @return list<T>
     */
    private function rotate(array $values, int $count): array
    {
        if ($values === []) {
            return [];
        }

        $taken = [];
        $total = min(count($values), max(1, $count));
        for ($i = 0; $i < $total; $i++) {
            $taken[] = $values[($this->rotation_cursor + $i) % count($values)];
        }
        $this->rotation_cursor += $total;

        return $taken;
    }

    private function daysAgo(int $days): DateTimeImmutable
    {
        $interval = new DateInterval(sprintf('P%dD', abs($days)));
        $now = new DateTimeImmutable();

        return $days >= 0 ? $now->sub($interval) : $now->add($interval);
    }

    /**
     * @return list<string>
     */
    private function parseList(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    private function outputStats(): void
    {
        $rows = [];
        $created = 0;
        $reused = 0;

        ksort($this->stats);
        foreach ($this->stats as $itemtype => [$itemtype_created, $itemtype_reused]) {
            $rows[] = [$itemtype, $itemtype_created, $itemtype_reused];
            $created += $itemtype_created;
            $reused += $itemtype_reused;
        }

        $this->io->table(['Itemtype', 'Created', 'Already there'], $rows);
        $this->io->success(sprintf('%d record(s) created, %d already existed.', $created, $reused));
    }
}
