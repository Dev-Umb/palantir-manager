<?php

return [
    'queue' => 'procurement',
    'connection' => env('HUB_QUEUE_CONNECTION', 'database'),
    'allow_project_ai' => env('HUB_ALLOW_PROJECT_AI', false),
    'provider' => env('HUB_AI_PROVIDER', 'aimon'),
    'model' => 'gpt-5.6-luna',
    'reasoning_effort' => 'low',
    'max_output_tokens' => 3500,
    'evidence_limit' => 10,
    'project_limit' => 20,
    'max_corrections' => 2,
    'max_pages' => 20,
    'max_bytes' => 4000000,
    'model_timeout' => 60,
    'http_timeout' => 15,
    'sample_limit' => 100,
    'synonyms' => [
        '钢模板' => ['steel formwork', 'steel mould', 'steel mold'], '模板' => ['formwork', 'shuttering'],
        '台车' => ['tunnel lining trolley'], '挂篮' => ['form traveller', 'form traveler'],
        '钢结构' => ['structural steel', 'steel structure'], '钢箱梁' => ['steel box girder'],
        '钢桥' => ['steel bridge'], '护栏' => ['guardrail', 'guard rail', 'bridge parapet'],
        '预埋件' => ['embedded steel'], '梁场' => ['precast yard', 'precasting yard'],
        '剪力钉' => ['shear stud'], '防落网' => ['bridge safety net'], '桥梁' => ['bridge construction', 'bridge rehabilitation', 'bridge replacement', 'bridge works', 'footbridge', 'foot bridge', 'bridge maintenance'],
    ],
    'keywords' => ['钢模板', '模板', '台车', '挂篮', '钢结构', '钢箱梁', '钢桥', '护栏', '预埋件', '梁场', '剪力钉', '防落网', '桥梁'],
];
