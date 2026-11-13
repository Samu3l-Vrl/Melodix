<?php

// tests/Unit/RoomCodeFormatTest.php — format des codes de salon (sans base de données).

use App\Services\RoomCodeGenerator;

it('produit des codes de 4 lettres sans caractères ambigus', function () {
    $generator = new RoomCodeGenerator;

    foreach (range(1, 500) as $ignored) {
        $code = $generator->candidate();

        expect($code)->toHaveLength(4)
            ->and($code)->toMatch('/^[A-HJ-NP-Z]{4}$/') // ni I, ni O, ni chiffre
            ->and(RoomCodeGenerator::isValid($code))->toBeTrue();
    }
});

it('exclut I, O, 0 et 1 de son alphabet', function () {
    expect(RoomCodeGenerator::ALPHABET)
        ->toHaveLength(24)
        ->not->toContain('I')
        ->not->toContain('O')
        ->not->toContain('0')
        ->not->toContain('1');
});

it('rejette les codes malformés', function (string $code) {
    expect(RoomCodeGenerator::isValid($code))->toBeFalse();
})->with(['ABC', 'ABCDE', 'abcd', 'AB1D', 'ABOD', 'ABID', '']);
