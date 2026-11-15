<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Présence des ordinateurs
    |--------------------------------------------------------------------------
    |
    | Délai, en secondes, sans signe de vie au-delà duquel un ordinateur hôte
    | est affiché hors ligne.
    |
    */

    'device_online_seconds' => (int) env('LEADDESK_DEVICE_ONLINE_SECONDS', 120),

    /*
    |--------------------------------------------------------------------------
    | Catalogue des sources de modèles IA
    |--------------------------------------------------------------------------
    |
    | Types de fournisseurs proposés à la connexion (RM-10). Le catalogue est
    | une aide à la saisie, pas une liste fermée : l'entreprise peut déclarer
    | un autre type compatible. Aucune clé n'est stockée sur le serveur.
    |
    */

    'ai_drivers' => [
        'anthropic' => 'Anthropic',
        'openai' => 'OpenAI',
        'google' => 'Google AI',
        'openrouter' => 'OpenRouter',
        'mistral' => 'Mistral AI',
        'groq' => 'Groq',
        'nvidia' => 'NVIDIA',
        'deepseek' => 'DeepSeek',
        'together' => 'Together AI',
        'fireworks' => 'Fireworks AI',
        'deepinfra' => 'DeepInfra',
        'cerebras' => 'Cerebras',
        'huggingface' => 'Hugging Face Inference Providers',
        'cloudflare' => 'Cloudflare AI Gateway',
        'anyrouter' => 'AnyRouter',
        'freebuff' => 'Freebuff',
    ],

];
