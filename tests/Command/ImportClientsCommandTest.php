<?php

namespace App\Tests\Command;

use App\Entity\User;
use App\Enum\ClientType;
use Doctrine\ORM\EntityManagerInterface;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class ImportClientsCommandTest extends KernelTestCase
{
    private CommandTester $tester;
    private EntityManagerInterface $em;
    private string $file;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->tester = new CommandTester($application->find('app:import:clients'));
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // the clients of this file only: other tables may still point at users of other tests
        $this->em->createQuery('DELETE FROM '.User::class.' u WHERE u.inn IN (:inns) OR u.name = :person OR u.email = :email')
            ->setParameters(['inns' => ['0323347497', '032500432033', '0326036852', '0326510825', '0300010384'], 'person' => 'Носкова Виктория Анатольевна', 'email' => 'diamed2005@yandex.ru'])
            ->execute();
        $this->file = sys_get_temp_dir().'/clients-'.bin2hex(random_bytes(4)).'.xlsx';
        $this->writeFile();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function testImportsClientsByTheirRequisites(): void
    {
        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        $display = $this->tester->getDisplay();
        self::assertStringContainsString('Клиентов в файле: 6', $display);
        self::assertMatchesRegularExpression('/\s6\s+0\s+0\s/u', $display);

        $company = $this->client('0323347497');
        self::assertSame([ClientType::Legal, 'Специализированная служба МАУ', '032301001', null, null], [$company->getClientType(), $company->getCompany(), $company->getKpp(), $company->getEmail(), $company->getName()]);
        self::assertTrue($company->isClient());
        self::assertTrue($company->isEmailVerified()); // can be picked for plans and bookings

        $entrepreneur = $this->client('032500432033');
        self::assertSame([ClientType::Entrepreneur, 'Шагдарова Индира Валерьевна ИП', null], [$entrepreneur->getClientType(), $entrepreneur->getCompany(), $entrepreneur->getKpp()]);

        // an ИНН written as a number lost its leading zero in the file
        self::assertSame('СЗ БАРХАН ООО', $this->client('0300010384')->getCompany());

        // one email, two companies: the second one is a card of its own, without the email
        self::assertSame(['diamed2005@yandex.ru', '8(301)2441766'], [$this->client('0326036852')->getEmail(), $this->client('0326036852')->getPhone()]);
        self::assertNull($this->client('0326510825')->getEmail());
        self::assertStringContainsString('ДИАГРУПП ООО ДК: почта diamed2005@yandex.ru уже есть у другого пользователя', $display);

        // no ИНН: a private person
        $person = $this->em->getRepository(User::class)->findOneBy(['name' => 'Носкова Виктория Анатольевна']);
        self::assertSame(ClientType::Individual, $person->getClientType());
        self::assertStringContainsString("Без ИНН — заведены как физ. лица", $display);
    }

    public function testRunningAgainAddsNothingAndFillsInWhatWasMissing(): void
    {
        $this->tester->execute(['file' => $this->file]);
        $this->client('0323347497')->setPhone(null);
        $this->em->flush();

        $this->tester->execute(['file' => $this->file]);

        $this->tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/\s0\s+0\s+6\s/u', $this->tester->getDisplay());
        self::assertSame(1, $this->em->getRepository(User::class)->count(['inn' => '0323347497']));
    }

    public function testDryRunSavesNothing(): void
    {
        $this->tester->execute(['file' => $this->file, '--dry-run' => true]);

        $this->tester->assertCommandIsSuccessful();
        self::assertStringContainsString('Пробный запуск', $this->tester->getDisplay());
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['inn' => '0323347497']));
    }

    private function client(string $inn): User
    {
        $this->em->clear();

        return $this->em->getRepository(User::class)->findOneBy(['inn' => $inn]) ?? self::fail('No client '.$inn);
    }

    private function writeFile(): void
    {
        $writer = new Writer();
        $writer->openToFile($this->file);
        $writer->addRows([
            Row::fromValues(['', 'Контрагент', 'Email', 'ИНН', 'КПП', 'Телефон']),
            Row::fromValues(['', 'Специализированная служба МАУ', '', '0323347497', '032301001']),
            Row::fromValues(['', 'Шагдарова Индира Валерьевна ИП', '', '032500432033']),
            Row::fromValues(['', 'СЗ БАРХАН ООО', '', 300010384, 30001001]), // typed as numbers
            Row::fromValues(['', 'ДИАГРУПП ООО', 'diamed2005@yandex.ru', '0326036852', '032601001', '8(301)2441766']),
            Row::fromValues(['', 'ДИАГРУПП ООО ДК', 'diamed2005@yandex.ru', '0326510825', '032301001', '8 (3012) 44-17-80']),
            Row::fromValues(['', 'Носкова Виктория Анатольевна']),
            Row::fromValues([]),
        ]);
        $writer->close();
    }
}
