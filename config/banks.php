<?php

declare(strict_types=1);

return [
    'gcb' => [
        'name'            => 'GCB Bank Limited',
        'header_signature'=> 'GCB Bank Limited',
        'period_row'      => 'Statement Period',
        'opening_row'     => 'Opening Balance',
        'closing_row'     => 'Closing Balance',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Transaction Date',
            'value_date'       => 'Value Date',
            'description'      => 'Description',
            'reference'        => 'Reference',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],

    'stanbic' => [
        'name'            => 'Stanbic Bank Ghana',
        'header_signature'=> 'Stanbic',
        'period_row'      => 'Period',
        'opening_row'     => 'Opening',
        'closing_row'     => 'Closing',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Date',
            'value_date'       => 'Value Date',
            'description'      => 'Narration',
            'reference'        => 'Ref',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],

    'gtb' => [
        'name'            => 'Guaranty Trust Bank',
        'header_signature'=> 'GTBank',
        'period_row'      => 'Period',
        'opening_row'     => 'Opening Balance',
        'closing_row'     => 'Closing Balance',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Trans Date',
            'value_date'       => 'Value Date',
            'description'      => 'Description',
            'reference'        => 'Ref',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],

    'ecobank' => [
        'name'            => 'Ecobank Ghana',
        'header_signature'=> 'Ecobank',
        'period_row'      => 'Statement Period',
        'opening_row'     => 'Opening Balance',
        'closing_row'     => 'Closing Balance',
        'currency'        => 'GHS',
        'columns'         => [
            'transaction_date' => 'Date',
            'value_date'       => 'Value Date',
            'description'      => 'Description',
            'reference'        => 'Reference',
            'debit'            => 'Debit',
            'credit'           => 'Credit',
            'running_balance'  => 'Balance',
        ],
    ],
];
