<?php

namespace Ambta\DoctrineEncryptBundle\Tests;

use Ambta\DoctrineEncryptBundle\Encryptors\AES192Encryptor;
use PHPUnit\Framework\TestCase;

/**
 * Class AES256EncryptorTest.
 */
class AES192EncryptorTest extends TestCase
{
    const SECRET_KEY = '624758dbe24e20067b27fc3cef22dc61';

    /**
     * @test
     */
    public function checkEncryptor()
    {
        $aes = new AES192Encryptor(self::SECRET_KEY);

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
    public function checkEncryptorWithoutString($data)
    {
        $aes = new AES192Encryptor(self::SECRET_KEY);
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
