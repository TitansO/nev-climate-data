<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Enum\UserRole;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * One demonstration user per UserRole case (cahier des charges 5.2: Admin,
 * Internal Analyst, External Partner — "Visitor" has no account by design,
 * see README's Authentification section). Dev/demo only: the password is
 * a fixed, publicly-documented placeholder (see README A1.6 section), never
 * a real credential, and this fixture is never registered for the `prod`
 * environment (DoctrineFixturesBundle is `dev`+`test` only in bundles.php).
 *
 * Password hashing mirrors AuthenticationControllerTest: password_hash()
 * with PASSWORD_DEFAULT, matching Symfony's "auto" hasher so the resulting
 * hash verifies correctly at login regardless of which concrete algorithm
 * "auto" resolves to.
 *
 * Reference name for other fixtures: self::userReference($role).
 */
final class UserFixtures extends Fixture
{
    public const DEMO_PASSWORD = 'ClimateDemo2026!';

    /**
     * [name, email, UserRole].
     *
     * @var list<array{0: string, 1: string, 2: UserRole}>
     */
    private const USERS = [
        ['Amina Diallo', 'admin@nev-climate-data.demo', UserRole::Admin],
        ['Kwame Mensah', 'analyste@nev-climate-data.demo', UserRole::InternalAnalyst],
        ['Fatou Ndiaye', 'partenaire@nev-climate-data.demo', UserRole::ExternalPartner],
    ];

    public static function userReference(UserRole $role): string
    {
        return 'user_'.$role->value;
    }

    public function load(ObjectManager $manager): void
    {
        $passwordHash = password_hash(self::DEMO_PASSWORD, \PASSWORD_DEFAULT);

        foreach (self::USERS as [$name, $email, $role]) {
            $user = new User($name, $email, $passwordHash, $role);
            $manager->persist($user);
            $this->addReference(self::userReference($role), $user);
        }

        $manager->flush();
    }
}
