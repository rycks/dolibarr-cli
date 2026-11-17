<?php

declare(strict_types=1);

use App\Util\Anonymizer;

it('builds ASCII logins and emails from accented fake names', function (): void {
    foreach (range(1, 300) as $id) {
        expect(Anonymizer::fakeLogin($id))->toMatch('/^[a-z.\-]+[0-9]+$/')
            ->and(Anonymizer::fakeEmail($id))->toMatch('/^[a-z.\-]+[0-9]+@example\.com$/')
            ->and(Anonymizer::fakeEmail($id, 'company'))->toMatch('/^contact[0-9]+@[a-z]+\.example\.com$/');
    }
    expect(Anonymizer::ascii('Hélène.Lefèvre'))->toBe('helene.lefevre');
});

