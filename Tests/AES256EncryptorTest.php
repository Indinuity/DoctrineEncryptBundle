<?php

namespace Ambta\DoctrineEncryptBundle\Tests;

use Ambta\DoctrineEncryptBundle\Encryptors\AES256Encryptor;
use PHPUnit\Framework\TestCase;

/**
 * Class AES256EncryptorTest.
 */
class AES256EncryptorTest extends TestCase
{
    const SECRET_KEY = '154758dbe24e20067b27fc3cef22dc61';

    /**
     * @test
     */
    public function checkEncryptor()
    {
        $aes = new AES256Encryptor(self::SECRET_KEY);

        $data = 'Content some data!';
        $encryptData = $aes->encrypt($data);

        self::assertEquals($data, $aes->decrypt($encryptData));
    }

    /**
     * @test
     * @dataProvider getContentData()
     *
     * @param mixed $data
     */
    public function checkEncryptorOtherTypes($data)
    {
        $aes = new AES256Encryptor(self::SECRET_KEY);
        $encryptData = $aes->encrypt($data);

        self::assertEquals($encryptData, $data);
        self::assertEquals($data, $aes->decrypt($encryptData));
    }

    /**
     * @return array
     */
    public function getContentData()
    {
        return [
            [1234],
            [100.123],
            [true],
            [false],
            [null],
        ];
    }
}
