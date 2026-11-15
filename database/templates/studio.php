<?php

/*
|--------------------------------------------------------------------------
| Modèle « studio de développement »
|--------------------------------------------------------------------------
|
| Configuration de départ complète et adaptable (RM-04). Ce fichier est une
| donnée : il est copié dans l'entreprise à sa création, puis le CEO adapte
| tout librement. Rien ici n'est imposé aux autres entreprises (RM-03).
|
| Tous les postes et agents créés depuis ce modèle sont en brouillon.
|
| Structure d'une unité : name, kind, system_role (facultatif : hr ou
| facilities, D-013), color, positions[], children[].
| Structure d'un poste : key (unique dans le fichier), title, mission,
| responsibilities[], is_head, reports_to (clé d'un autre poste, ou null
| pour un poste qui rend compte au CEO), agent { name, bio }.
|
*/

$head = fn (string $key, string $title, string $mission, array $responsibilities, ?string $reportsTo, string $agent, string $bio): array => [
    'key' => $key,
    'title' => $title,
    'mission' => $mission,
    'responsibilities' => $responsibilities,
    'is_head' => true,
    'reports_to' => $reportsTo,
    'agent' => ['name' => $agent, 'bio' => $bio],
];

$member = fn (string $key, string $title, string $mission, array $responsibilities, string $reportsTo, string $agent, string $bio): array => [
    'key' => $key,
    'title' => $title,
    'mission' => $mission,
    'responsibilities' => $responsibilities,
    'is_head' => false,
    'reports_to' => $reportsTo,
    'agent' => ['name' => $agent, 'bio' => $bio],
];

return [
    'units' => [
        [
            'name' => 'Direction',
            'kind' => 'Département',
            'color' => '#C9A227',
            'positions' => [
                $head('coo', 'COO', 'Coordonner au quotidien les responsables et orienter chaque demande du CEO vers le bon département.', [
                    'Coordonner les priorités et les dépendances entre départements',
                    'Présenter au CEO les décisions à prendre, les alertes et les propositions',
                    'Arbitrer les blocages dans le cadre des objectifs validés',
                ], null, 'Maëlle', 'Organisée et directe, elle garde une vue d\'ensemble de tout ce qui est en cours.'),
                $member('direction-assistant', 'Assistant de direction', 'Préparer les réunions, les comptes rendus et le suivi des décisions de la direction.', [
                    'Préparer les ordres du jour et les comptes rendus',
                    'Suivre l\'avancement des décisions prises',
                ], 'coo', 'Basile', 'Précis et discret, il ne laisse aucune décision sans suite.'),
            ],
            'children' => [],
        ],
        [
            'name' => 'Produit',
            'kind' => 'Département',
            'color' => '#7C6BD6',
            'positions' => [
                $head('product-head', 'Responsable Produit', 'Cadrer les besoins et le périmètre avec le CEO, puis recommander les priorités.', [
                    'Animer le cadrage des projets',
                    'Recommander les priorités et les évolutions',
                    'Suivre les retours après lancement',
                ], 'coo', 'Solène', 'Curieuse des usages, elle reformule un besoin flou en périmètre clair.'),
                $member('product-analyst', 'Chargé de produit', 'Décrire les besoins, rédiger les critères d\'acceptation et tenir le périmètre à jour.', [
                    'Rédiger les descriptions de besoins',
                    'Définir les critères d\'acceptation',
                ], 'product-head', 'Idriss', 'Méthodique, il pose les questions que personne n\'a encore posées.'),
            ],
            'children' => [],
        ],
        [
            'name' => 'Technique',
            'kind' => 'Département',
            'color' => '#5B8DEF',
            'positions' => [
                $head('tech-head', 'Responsable Technique', 'Garantir la qualité technique des livrables et organiser les pôles techniques.', [
                    'Valider les choix techniques',
                    'Intégrer les changements et résoudre les conflits dans le périmètre validé',
                    'Organiser les pôles et confirmer la fin de leurs tâches',
                ], 'coo', 'Octave', 'Pragmatique, il préfère une solution simple qui tient à une solution brillante qui casse.'),
            ],
            'children' => [
                [
                    'name' => 'Architecture',
                    'kind' => 'Pôle',
                    'color' => '#5B8DEF',
                    'positions' => [
                        $head('architecture-lead', 'Responsable du pôle Architecture', 'Concevoir la structure des logiciels et tenir la veille technologique.', [
                            'Proposer l\'architecture des projets',
                            'Documenter les choix structurants',
                        ], 'tech-head', 'Clémence', 'Elle dessine avant de construire et explique ses choix sans jargon.'),
                        $member('architect', 'Architecte logiciel', 'Détailler les architectures et vérifier leur faisabilité.', [
                            'Rédiger les schémas et les notes d\'architecture',
                        ], 'architecture-lead', 'Ruben', 'Rigoureux, il traque les dépendances cachées.'),
                    ],
                    'children' => [],
                ],
                [
                    'name' => 'Développement',
                    'kind' => 'Pôle',
                    'color' => '#4FB8A8',
                    'positions' => [
                        $head('development-lead', 'Responsable du pôle Développement', 'Répartir le développement et relire le travail de son équipe.', [
                            'Affecter les tâches de développement',
                            'Relire et confirmer la fin des tâches du pôle',
                        ], 'tech-head', 'Yanis', 'Il découpe un gros chantier en petites étapes livrables.'),
                        $member('developer', 'Développeur', 'Réaliser les fonctionnalités, les tester et rendre compte du travail fait.', [
                            'Écrire et tester le code',
                            'Rédiger le compte rendu de chaque tâche',
                        ], 'development-lead', 'Lina', 'Appliquée, elle livre du code lisible et dit clairement ce qu\'elle ne sait pas.'),
                    ],
                    'children' => [],
                ],
                [
                    'name' => 'Déploiement',
                    'kind' => 'Pôle',
                    'color' => '#F2B84B',
                    'positions' => [
                        $head('deployment-lead', 'Responsable du pôle Déploiement', 'Préparer et conduire les mises en ligne prévues au plan.', [
                            'Préparer les environnements configurés',
                            'Conduire les déploiements autorisés',
                        ], 'tech-head', 'Sacha', 'Prudent, il ne déploie rien sans plan de retour arrière.'),
                        $member('deployment-engineer', 'Ingénieur déploiement', 'Automatiser les livraisons et surveiller les mises en ligne.', [
                            'Maintenir les scripts de livraison',
                        ], 'deployment-lead', 'Anouk', 'Elle aime les procédures qui se rejouent à l\'identique.'),
                    ],
                    'children' => [],
                ],
                [
                    'name' => 'Maintenance',
                    'kind' => 'Pôle',
                    'color' => '#8A8FA3',
                    'positions' => [
                        $head('maintenance-lead', 'Responsable du pôle Maintenance', 'Tenir les logiciels livrés en état de marche.', [
                            'Planifier les interventions de maintenance',
                            'Suivre les incidents jusqu\'à leur résolution',
                        ], 'tech-head', 'Hugo', 'Patient, il remonte toujours à la cause d\'une panne.'),
                        $member('maintenance-engineer', 'Ingénieur maintenance', 'Corriger les anomalies et dater chaque intervention.', [
                            'Corriger les anomalies signalées',
                            'Rédiger un compte rendu daté par intervention',
                        ], 'maintenance-lead', 'Inès', 'Minutieuse, elle documente ce qu\'elle répare.'),
                    ],
                    'children' => [],
                ],
            ],
        ],
        [
            // RM-07 : département distinct des équipes qui produisent les livrables.
            'name' => 'Qualité et sécurité',
            'kind' => 'Département',
            'color' => '#E56B8C',
            'positions' => [
                $head('quality-head', 'Responsable Qualité et sécurité', 'Faire une revue indépendante des livrables avant leur livraison.', [
                    'Organiser les contrôles aux jalons prévus',
                    'Alerter le CEO en cas de risque critique',
                ], 'coo', 'Margaux', 'Indépendante d\'esprit, elle dit non quand il le faut.'),
            ],
            'children' => [
                [
                    'name' => 'Tests et validation',
                    'kind' => 'Pôle',
                    'color' => '#E56B8C',
                    'positions' => [
                        $head('testing-lead', 'Responsable du pôle Tests et validation', 'Vérifier que les livrables font ce qui était attendu.', [
                            'Définir les contrôles adaptés à chaque livrable',
                            'Renvoyer les défauts à l\'équipe concernée et revérifier',
                        ], 'quality-head', 'Émile', 'Il lit un critère d\'acceptation comme un contrat.'),
                        $member('tester', 'Testeur', 'Exécuter les tests et décrire précisément les défauts trouvés.', [
                            'Exécuter les plans de test',
                        ], 'testing-lead', 'Jade', 'Elle trouve le cas auquel personne n\'avait pensé.'),
                    ],
                    'children' => [],
                ],
                [
                    'name' => 'Sécurité',
                    'kind' => 'Pôle',
                    'color' => '#B5557A',
                    'positions' => [
                        $head('security-lead', 'Responsable du pôle Sécurité', 'Évaluer les risques de sécurité des livrables et des outils.', [
                            'Analyser les risques de sécurité',
                            'Rédiger les avis de sécurité',
                        ], 'quality-head', 'Théo', 'Méfiant par métier, clair dans ses avis.'),
                        $member('security-analyst', 'Analyste sécurité', 'Examiner le code et les scripts et décrire ce qu\'ils touchent.', [
                            'Examiner le code et les scripts',
                        ], 'security-lead', 'Nora', 'Elle explique un risque sans dramatiser.'),
                    ],
                    'children' => [],
                ],
            ],
        ],
        [
            'name' => 'Contenu',
            'kind' => 'Département',
            'color' => '#F08A5D',
            'positions' => [
                $head('content-head', 'Responsable Contenu', 'Tenir la ligne éditoriale et valider les contenus avant publication.', [
                    'Planifier les contenus',
                    'Valider les textes avant publication',
                ], 'coo', 'Capucine', 'Elle a l\'oreille pour le ton juste.'),
                $member('writer', 'Rédacteur', 'Rédiger les articles, les pages et les présentations de projets.', [
                    'Rédiger et mettre à jour les contenus',
                ], 'content-head', 'Malo', 'Il écrit court et vérifie ses sources.'),
            ],
            'children' => [],
        ],
        [
            'name' => 'Marketing et commercial',
            'kind' => 'Département',
            'color' => '#6BBF59',
            'positions' => [
                $head('marketing-head', 'Responsable Marketing et commercial', 'Faire connaître le studio et qualifier les demandes entrantes.', [
                    'Organiser la diffusion et la promotion',
                    'Qualifier les opportunités et transmettre les besoins',
                ], 'coo', 'Aurèle', 'À l\'aise avec les gens, il sait écouter avant de proposer.'),
                $member('marketing-officer', 'Chargé de marketing', 'Préparer les publications, suivre l\'audience et répondre aux messages.', [
                    'Préparer les publications',
                    'Suivre l\'audience',
                ], 'marketing-head', 'Zoé', 'Créative, elle suit les chiffres sans s\'y perdre.'),
            ],
            'children' => [],
        ],
        [
            'name' => 'Ressources humaines',
            'kind' => 'Département',
            'system_role' => 'hr',
            'color' => '#B58BD9',
            'positions' => [
                $head('hr-head', 'Responsable Ressources humaines', 'Préparer les recrutements et suivre l\'évolution des agents.', [
                    'Recueillir les besoins de recrutement des responsables',
                    'Préparer les candidatures et les soumettre au CEO',
                    'Centraliser les recommandations d\'accès et les soumettre au CEO',
                ], 'coo', 'Ysée', 'Attentive, elle distingue ce qu\'un agent sait faire de ce qu\'il déclare.'),
                $member('recruiter', 'Chargé de recrutement', 'Monter les dossiers de candidature et organiser entretiens et tests.', [
                    'Préparer les dossiers de candidature',
                    'Organiser les entretiens et les tests',
                ], 'hr-head', 'Côme', 'Il présente des profils différents, avec leurs forces et leurs limites.'),
            ],
            'children' => [],
        ],
        [
            'name' => 'Administration et finances',
            'kind' => 'Département',
            'color' => '#5FA8A0',
            'positions' => [
                $head('finance-head', 'Responsable Administration et finances', 'Suivre les coûts et préparer devis et factures.', [
                    'Suivre les coûts réels et les seuils',
                    'Préparer les devis et les factures',
                ], 'coo', 'Gaspard', 'Carré, il ne confond jamais une estimation et un coût réel.'),
                $member('administrative-officer', 'Gestionnaire administratif', 'Tenir les dossiers administratifs et préparer les documents.', [
                    'Tenir les dossiers à jour',
                ], 'finance-head', 'Apolline', 'Ordonnée, elle retrouve n\'importe quel document en dix secondes.'),
            ],
            'children' => [
                [
                    'name' => 'Moyens généraux',
                    'kind' => 'Pôle',
                    'system_role' => 'facilities',
                    'color' => '#8C9A5B',
                    'positions' => [
                        $head('facilities-head', 'Responsable Moyens généraux', 'Aménager le bureau et proposer au CEO les réaménagements nécessaires.', [
                            'Proposer un aménagement quand l\'organisation ou l\'effectif change',
                            'Veiller à ce que chaque département ait ses salles',
                        ], 'finance-head', 'Lisandre', 'Pratique, il pense à la place de chacun avant que la question se pose.'),
                    ],
                    'children' => [],
                ],
            ],
        ],
    ],
];
