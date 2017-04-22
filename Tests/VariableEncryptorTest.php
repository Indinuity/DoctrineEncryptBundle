<?php

namespace Ambta\DoctrineEncryptBundle\Tests;

use Ambta\DoctrineEncryptBundle\Encryptors\VariableEncryptor;
use PHPUnit\Framework\TestCase;

/**
 * Class VariableEncryptorTest.
 */
class VariableEncryptorTest extends TestCase
{
    const SECRET_KEY = '624758dbe24e20067b27fc3cef22dc61';

    /**
     * @test
     */
    public function checkEncryptor()
    {
        $aes = new VariableEncryptor(self::SECRET_KEY);

        $data = 'Content some data!';
        $encryptData = $aes->encrypt($data);

        self::assertEquals($data, $aes->decrypt($encryptData));
    }

    /**
     * @test
     */
    public function checkEncryptorWithoutString()
    {
        $aes = new VariableEncryptor(self::SECRET_KEY);

        $data = 12345;
        $encryptData = $aes->encrypt($data);

        self::assertEquals($encryptData, $data);
        self::assertEquals($data, $aes->decrypt($encryptData));
    }
}
