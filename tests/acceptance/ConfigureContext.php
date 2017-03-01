<?php

use Behat\Behat\Tester\Exception\PendingException;
use Behat\MinkExtension\Context\MinkContext;

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../vendor/autoload.php';

class ConfigureContext extends MinkContext
{
    use PagarMe\Magento\Test\Helper\CustomerDataProvider;
    use PagarMe\Magento\Test\Helper\ProductDataProvider;

    const ADMIN_PASSWORD = 'admin123';

    private $adminUser;

    private $magentoUrl;

    private $customer;

    /**
     * @BeforeScenario
     */
    public function setUp()
    {
        $this->customer = $this->getCustomer();
        $this->customer->save();

        $this->customerAddress = $this->getCustomerAddress();
        $this->customerAddress->setCustomerId($this->customer->getId());
        $this->customerAddress->save();

        $this->product = $this->getProduct();
        $this->product->save();

        $stock = $this->getProductStock();
        $stock->assignProduct($this->product);
        $stock->save();
    }

    /**
     * @Given a admin user
     */
    public function aAdminUser()
    {
        $this->adminUser = Mage::getModel('admin/user')
            ->setData(
                array(
                    'username'  => mktime() . '_admin',
                    'firstname' => 'Admin',
                    'lastname'  => 'Admin',
                    'email'     => mktime() . '@admin.com',
                    'password'  => self::ADMIN_PASSWORD,
                    'is_active' => 1
                )
            )->save();

        $this->adminUser->setRoleIds(
            array(1)
        )
        ->setRoleUserId($this->adminUser->getUserId())
        ->saveRelations();
    }

    /**
     * @Given a api key
     */
    public function aApiKey()
    {
        $this->apiKey = PAGARME_API_KEY;
    }

    /**
     * @Given a encryption key
     */
    public function aEncryptionKey()
    {
        $this->encryptionKey = PAGARME_ENCRYPTION_KEY;
    }

    /**
     * @When I access the admin
     */
    public function iAccessTheAdmin()
    {
        $session = $this->getSession();
        $session->visit(getenv('MAGENTO_URL') . 'index.php/admin');

        $page = $session->getPage();

        $inputLogin = $page->find('named', array('id', 'username'));
        $inputLogin->setValue($this->adminUser->getUsername());

        $inputPassword = $page->find('named', array('id', 'login'));
        $inputPassword->setValue(self::ADMIN_PASSWORD);

        $page->pressButton('Login');
    }

    /**
     * @When go to system configuration page
     */
    public function goToSystemConfigurationPage()
    {
        $session = $this->getSession();
        $page = $session->getPage();

        $popup = $page->find('css', '.message-popup-head a');

        if ($popup instanceof \Behat\Mink\Element\NodeElement) {
            $popup->click();
        }

        $page->find('named', array('link', 'System'))
            ->mouseOver();

        $page->find('named', array('link', 'Configuration'))
            ->click();

        $page->find('named', array('link', 'Payment Methods'))
            ->click();

        $this->spin(function () use ($page) {
            return $page->findById('config_edit_form') != null;
        }, 10);
    }

    /**
     * @When insert an API key
     */
    public function insertAnApiKey()
    {
        $session = $this->getSession();
        $page = $session->getPage();

        $page->find(
            'named',
            array(
                'link',
                'Pagar.me'
            )
        )
        ->click();

        $page->find(
            'named',
            array(
                'id',
                'payment_pagarme_settings_api_key'
            )
        )->setValue($this->apiKey);
    }

    /**
     * @When insert an encryption key
     */
    public function insertAnEncryptionKey()
    {
        $session = $this->getSession();
        $page = $session->getPage();

        $page->find(
            'named',
            array(
                'id',
                'payment_pagarme_settings_encryption_key'
            )
        )->setValue($this->encryptionKey);
    }

    /**
     * @When save configuration
     */
    public function saveConfiguration()
    {
        $session = $this->getSession();
        $page = $session->getPage();

        $page->pressButton('Save Config');
    }

    /**
     * @Then the configuration must be saved with success
     */
    public function theConfigurationMustBeSavedWithSuccess()
    {
        $session = $this->getSession();
        $page = $session->getPage();
        $successMsg = null;

        $this->spin(function () use ($page) {
            return $page->find('css', '.success-msg') != null;
        }, 10);

        $successMsg = $page->find('css', '.success-msg');

        \PHPUnit_Framework_TestCase::assertEquals("The configuration has been saved.", $successMsg->getText());
    }

    /**
     * @When select payment method :paymentMethod
     */
    public function selectPaymentMethod($paymentMethod)
    {
        $session = $this->getSession();
        $page = $session->getPage();

        $page->find(
            'named',
            array(
                'id',
                'payment_pagarme_settings_payment_methods'
            )
        )->selectOption($paymentMethod);
    }

    /**
     * @When any customer try to buy any product
     */
    public function anyCustomerTryToBuyAnyProduct()
    {
        $session = $this->getSession();
        $session->visit(getenv('MAGENTO_URL'));
        $page = $session->getPage();

        $page->pressButton(
            Mage::helper('pagarme_checkout')->__('Add to Cart')
        );

        $page->pressButton(
            Mage::helper('pagarme_checkout')->__('Proceed to Checkout')
        );

        $this->fillField(
            Mage::helper('pagarme_checkout')->__('Email Address'),
            $this->customer->getEmail()
        );

        $this->fillField(
            Mage::helper('pagarme_checkout')->__('Password'),
            $this->customer->getPassword()
        );

        $page->pressButton('Login');

        $page->find('css', '#billing-buttons-container button')->press();

        $this->waitForElement('#checkout-step-shipping_method', 5000);

        $page->find('css', '#shipping-method-buttons-container button')
            ->press();

        $this->waitForElement('#checkout-step-payment', 5000);

        $page->find('css', '#p_method_pagarme_checkout')->click();
        $page->pressButton(
            Mage::helper('pagarme_checkout')->__('Fill in the card data')
        );

        $session->switchToIframe(
            $page->find('css', 'iframe')->getAttribute('name')
        );

        $this->pagarMeCheckoutModal = $session->getPage();
    }

    /**
     * @Then the :paymentMethodButton button must not be found
     */
    public function theButtonMustNotBeFound($paymentMethodButton)
    {
        \PHPUnit_Framework_TestCase::assertFalse(
            $this->pagarMeCheckoutModal
                ->findButton($paymentMethodButton)
                ->isVisible()
        );
    }


    public function waitForElement($element, $timeout)
    {
        $this->getSession()->wait(
            $timeout,
            "document.querySelector('${element}').style.display != 'none'"
        );
    }

    public function spin($lambda, $wait)
    {
        for ($i = 0; $i < $wait; $i++) {
            try {
                if ($lambda($this)) {
                    return true;
                }
            } catch (\Exception $e) {
            }

            sleep(1);
        }
    }

    /**
     * @AfterScenario
     */
    public function tearDown()
    {
        $this->adminUser->delete();
        $this->customer->delete();
        $this->product->delete();

        Mage::getModel('core/config')->saveConfig(
            'payment/pagarme_settings/payment_methods',
            'credit_card,boleto'
        );
    }
}
