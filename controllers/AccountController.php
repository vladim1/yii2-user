<?php

/**
 * @copyright Copyright &copy; Kartik Visweswaran, communityii, 2014 - 2015
 * @package communityii/yii2-user
 * @version 1.0.0
 * @see https://github.com/communityii/yii2-user
 */

namespace comyii\user\controllers;

use Exception;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\helpers\ArrayHelper;
use yii\helpers\Html;
use yii\helpers\Url;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\authclient\AuthAction;
use comyii\user\Module;
use comyii\user\models\LoginForm;
use comyii\user\models\RecoveryForm;
use comyii\user\models\User;
use comyii\user\models\SocialProfile;
use comyii\user\events\account\RegistrationEvent;
use comyii\user\events\account\LoginEvent;
use comyii\user\events\account\LogoutEvent;
use comyii\user\events\account\RecoveryEvent;
use comyii\user\events\account\ResetEvent;
use comyii\user\events\account\ActivateEvent;
use comyii\user\events\account\AuthEvent;
use comyii\user\events\account\NewemailEvent;
use derekisbusy\haikunator\Haikunator;

/**
 * Account controller for authentication of various user actions.
 *
 * @author Kartik Visweswaran <kartikv2@gmail.com>
 * @since 1.0
 */
class AccountController extends BaseController
{
    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return $this->mergeBehaviors([
            'access' => [
                'class' => AccessControl::className(),
                'only' => ['logout', 'register', 'recovery', 'password'],
                'rules' => [
                    [
                        'actions' => ['register', 'recovery'],
                        'allow' => true,
                        'roles' => ['?'],
                    ],
                    [
                        'actions' => ['password', 'logout'],
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ]);
    }
    

    /**
     * @inheritdoc
     */
    public function actions()
    {
        $actions = [];
        if ($this->getConfig('socialSettings', 'enabled', false)) {
            $actions['auth'] = [
                'class' => AuthAction::classname(),
                'successCallback' => [$this, 'onAuthSuccess'],
            ];
        }
        $captcha = $this->getConfig('registrationSettings', 'captcha', false);
        if ($captcha !== false) {
            $captcha = ArrayHelper::getValue($captcha, 'action', []);
            $actions['captcha'] = $captcha;
        }
        return $actions;
    }

    /**
     * Social client authorization callback
     *
     * @param \yii\authclient\BaseClient $client
     *
     * @return \yii\web\Response
     * @throws BadRequestHttpException
     */
    public function onAuthSuccess($client)
    {
        /**
         * @var SocialProfile $socialClass
         * @var User          $userClass
         * @var SocialProfile $auth
         * @var User          $user
         * @var AuthEvent     $event
         */
        $socialClass = $this->fetchModel(Module::MODEL_SOCIAL_PROFILE);
        $userClass = $this->fetchModel(Module::MODEL_USER);
        $attributes = $client->getUserAttributes();
        $clientId = $client->getId();
        $clientTitle = $client->getTitle();
        $sourceId = (string)$attributes['id'];
        $email = $client->getEmail();
        $username = $client->getUsername();
        
        // generate random username if username is empty, less than min length, or random usernames have been enabled
        $randomUsernameGenerator = $this->getConfig('registrationSettings', 'randomUsernameGenerator', ["delimiter" => "."]);
        if (empty($username) || $username < $this->getConfig('registrationSettings', 'minUsernameLength', 5) ||
                $this->getConfig('registrationSettings', 'randomUsernames', false)
            ) {
            $i = 0;
            do {
                if (is_callable($randomUsernameGenerator)) {
                    $username = call_user_func($randomUsernameGenerator);
                } else {
                    $username = Haikunator::haikunate($randomUsernameGenerator);
                }
                $i++;
            } while($i < 10 && $userClass::find()->where(['username' => $username])->exists());
            unset($i);
        }
        
        $event = new AuthEvent;
        $event->client = $client;
        $event->userClass = $userClass;
        $event->socialClass = $socialClass;
        $auth = $socialClass::find()->where([
            'source' => $clientId,
            'source_id' => $attributes['id'],
        ])->one();
        $event->model = $auth;
        $this->_module->trigger(Module::EVENT_AUTH_BEGIN, $event);
        $transaction = static::tranInit($event);
        try {
            if (Yii::$app->user->isGuest) {
                if ($auth) { // login
                    $user = $auth->user;
                    Yii::$app->user->login($user);
                    $event->flashType = 'success';
                    $event->message = Yii::t(
                        'user',
                        'Logged in successfully with your <b>{client}</b> account.',
                        ['client' => $clientTitle]
                    );
                    $event->result = AuthEvent::RESULT_LOGGED_IN;
                } else { // signup
                    if (!empty($email) && $userClass::find()->where(['email' => $email])->exists()) {
                        $event->flashType = 'error';
                        $event->message = Yii::t(
                            'user',
                            'User with the same email as in <b>{client}</b> account already exists but is not linked to it. Login using email first to link it.',
                            ['client' => $clientTitle]
                        );
                        $event->result = AuthEvent::RESULT_DUPLICATE_EMAIL;
                    } else {
                        $minPassLen = $this->getConfig('registrationSettings', 'randomPasswordMinLength', 10);
                        $maxPassLen = $this->getConfig('registrationSettings', 'randomPasswordMaxLength', 14);
                        $password = Yii::$app->security->generateRandomString(rand($minPassLen, $maxPassLen));
                        $user = new $userClass([
                            'username' => $username,
                            'email' => $email,
                            'password' => $password,
                        ]);
                        $user->generateAuthKey();
                        $success = false;
                        if ($user->save()) {
                            $auth = new $socialClass([
                                'user_id' => $user->id,
                                'source' => $clientId,
                                'source_id' => $sourceId,
                            ]);
                            if ($auth->save()) {
                                $transaction->commit();
                                $success = true;
                                Yii::$app->user->login($user);
                            }
                        }
                        if (!$success) {
                            $event->result = AuthEvent::RESULT_SIGNUP_ERROR;
                            $event->flashType = 'error';
                            $event->message = Yii::t(
                                'user',
                                'Error while authenticating <b>{client}</b> account.<pre>{errors}</pre>',
                                ['client' => $clientTitle, 'errors' => print_r($user->getErrors(), true)]
                            );
                            throw new Exception('Error authenticating social client');
                        } else {
                            $event->result = AuthEvent::RESULT_SIGNUP_SUCCESS;
                            $event->flashType = 'success';
                            $event->message = Yii::t(
                                'user',
                                'Logged in successfully with your <b>{client}</b> account.',
                                ['client' => $clientTitle]
                            );
                        }
                    }
                }
            } else { // user already logged in
                if (!$auth) { // add auth provider
                    $user = Yii::$app->user;
                    $id = $user->id;
                    $auth = new $socialClass([
                        'user_id' => $id,
                        'source' => $clientId,
                        'source_id' => $attributes['id'],
                    ]);
                    $event->model = $auth;
                    if ($auth->save()) {
                        $transaction->commit();
                        $event->result = AuthEvent::RESULT_LOGGED_IN;
                        $event->flashType = 'success';
                        $event->message = Yii::t(
                            'user',
                            'Successfully authenticated <b>{client}</b> account for <b>{user}</b>.',
                            ['client' => $clientTitle, 'user' => $user->username]
                        );
                    } else {
                        $event->result = AuthEvent::RESULT_AUTH_ERROR;
                        $event->flashType = 'error';
                        $event->message = Yii::t(
                            'user',
                            'Error while authenticating <b>{client}</b> account for <b>{user}</b>.<pre>{errors}</pre>',
                            ['client' => $clientTitle, 'errors' => print_r($auth->getErrors(), true)]
                        );
                        throw new Exception('Error authenticating social client');
                    }
                } else {
                    $event->result = AuthEvent::RESULT_LOGGED_IN;
                    $event->flashType = 'success';
                    $event->message = Yii::t(
                        'user',
                        'You have already connected your <b>{client}</b> account previously. Logged in successfully.',
                        ['client' => $clientTitle]
                    );
                }
            }
        } catch (Exception $e) {
            static::tranRollback($transaction);
            $this->raise($e, $event);
        }
        $this->_module->trigger(Module::EVENT_AUTH_COMPLETE, $event);
        static::setFlash($event);
        if (!isset($event->redirectUrl)) {
            $event->redirectUrl = $this->fetchUrl('loginSettings', 'loginRedirectUrl');
        }
        return $this->redirect($event->redirectUrl);
    }

    /**
     * Login the current user after validating credentials, the password expiry, and password lock status.
     *
     * @return string|\yii\web\Response
     * @throws InvalidConfigException
     */
    public function actionLogin()
    {
        /**
         * @var LoginForm $model
         * @var Module    $m
         */
        $app = Yii::$app;
        $m = $this->_module;
        $event = new LoginEvent;
        if (!$app->user->isGuest) {
            $event->result = LoginEvent::RESULT_ALREADY_AUTH;
            $m->trigger(Module::EVENT_LOGIN_COMPLETE, $event);
            if ($app->user->returnUrl == Url::to($this->fetchUrl(Module::ACTION_LOGOUT))) {
                return $this->eventRedirect($event, $this->goHome());
            }
            return $this->eventRedirect($event, $this->goBack());
        }
        $class = $this->fetchModel(Module::MODEL_LOGIN);
        $post = $app->request->post();
        $model = new $class();
        $event->unlockExpiry = !empty($post) && !empty($post['unlock-account']);
        $model->scenario = $event->unlockExpiry ? Module::SCN_EXPIRY : Module::SCN_LOGIN;
        $event->model = $model;
        $event->redirectUrl = $this->fetchUrl('loginSettings', 'loginRedirectUrl');
        $event->authAction = $this->fetchUrl(Module::ACTION_SOCIAL_AUTH);
        $event->hasSocialAuth = $m->hasSocialAuth();
        $m->trigger(Module::EVENT_LOGIN_BEGIN, $event);
        $transaction = static::tranInit($event);
        try {
            if ($model->load($post) && $model->validate() && !$event->error) {
                $event->handled = false;
                $user = $model->getUser();
                $event->model = $user;
                if ($event->unlockExpiry) {
                    $user->setPassword($model->password_new);
                    $user->status_sec = null;
                    $user->save(false);
                    $event->flashType = 'success';
                    $event->message = Yii::t(
                        'user',
                        'Your password has been changed successfully and you have been logged in.'
                    );
                    $model->login($user);
                    $user->setLastLogin();
                    static::tranCommit($transaction);
                    $event->newPassword = true;
                    $event->result = LoginEvent::RESULT_SUCCESS;
                    $m->trigger(Module::EVENT_LOGIN_COMPLETE, $event);
                    static::setFlash($event);
                    return $this->eventRedirect($event, $this->safeRedirect());
                }
                $event->status = $model->login($user);
                if ($event->status === Module::STATUS_EXPIRED) {
                    $event->result = LoginEvent::RESULT_EXPIRED;
                    $event->flashType = 'error';
                    $event->message = Yii::t(
                        'user',
                        'Your password has expired. Change your password by completing the details below.'
                    );
                    $model->scenario = Module::SCN_EXPIRY;
                } elseif ($event->status === Module::STATUS_LOCKED) {
                    $event->result = LoginEvent::RESULT_LOCKED;
                    $event->flashType = 'error';
                    $link = Yii::t('user', 'Click {link} to reset your password and unlock your account.', [
                        'link' => Html::a(Yii::t('user', 'here'), $this->fetchUrl(Module::ACTION_RECOVERY))
                    ]);
                    $event->message = Yii::t(
                        'user',
                        'Your account has been locked due to multiple invalid login attempts. {reset}',
                        ['reset' => $link]
                    );
                } elseif ($event->status) {
                    $user->setLastLogin();
                    static::tranCommit($transaction);
                    $event->result = LoginEvent::RESULT_SUCCESS;
                    $m->trigger(Module::EVENT_LOGIN_COMPLETE, $event);
                    return $this->eventRedirect($event, $this->safeRedirect());
                }
            }
            static::setFlash($event);
            if ($post) {
                $event->result = LoginEvent::RESULT_FAIL;
                $m->trigger(Module::EVENT_LOGIN_COMPLETE, $event);
            }
        } catch (Exception $e) {
            static::tranRollback($transaction);
        }
        return $this->display($event->viewFile ? $event->viewFile : Module::VIEW_LOGIN, [
            'model' => $model,
            'hasSocialAuth' => $event->hasSocialAuth,
            'authAction' => $event->authAction,
            'loginTitle' => $event->loginTitle ? $event->loginTitle : $model->scenario === Module::SCN_EXPIRY ?
                Yii::t('user', 'Change Password') :
                Yii::t('user', 'Login'),
            'authTitle' => $event->authTitle ? $event->authTitle : Yii::t('user', 'Or Login Using')
        ]);
    }

    /**
     * User logout action
     *
     * @return \yii\web\Response
     */
    public function actionLogout()
    {
        Yii::$app->user->logout();
        $event = new LogoutEvent;
        $event->redirectUrl = $this->fetchUrl('loginSettings', 'logoutRedirectUrl');
        $this->_module->trigger(Module::EVENT_LOGOUT, $event);
        static::setFlash($event);
        return $this->eventRedirect($event, $this->goHome());
    }

    /**
     * User registration action
     *
     * @param string $type the user type
     *
     * @return string|\yii\web\Response
     * @throws InvalidConfigException
     */
    public function actionRegister($type = 'user')
    {
        /**
         * @var User   $model
         * @var Module $m
         */
        $m = $this->_module;
        $config = $m->registrationSettings;
        if (!$config['enabled']) {
            return $this->goBack();
        }
        $hasSocialAuth = $m->hasSocialAuth();
        $authAction = $this->fetchUrl(Module::ACTION_SOCIAL_AUTH);
        $class = $this->fetchModel(Module::MODEL_USER);
        $model = new $class(['scenario' => Module::SCN_REGISTER]);
        $event = new RegistrationEvent;
        $event->type = $type;
        $event->model = $model;
        $m->trigger(Module::EVENT_REGISTER_BEGIN, $event);
        $viewFile = $event->viewFile ? $event->viewFile : Module::VIEW_REGISTER;
        if ($model->load(Yii::$app->request->post()) && !$event->error) {
            if ($m->getRegistrationSetting('randomUsernames', $event->type)) {
                $model->setRandomUsername($event->type);
            }
            if ($m->getRegistrationSetting('randomPasswords', $event->type)) {
                $model->setRandomPassword($event->type);
            }
            $model->setPassword($model->password);
            $model->generateAuthKey();
            $model->status = Module::STATUS_PENDING;
            $transaction = static::tranInit($event);
            $valid = false;
            try {
                if ($model->save() && !$event->error) {
                    static::tranCommit($transaction);
                    $valid = true;
                }
            } catch (Exception $e) {
                static::tranRollback($transaction);
            }
            if ($valid) {
                $event->flashType = 'success';
                $activate = $event->activate !== null ? $event->activate : $config['autoActivate'];
                if ($activate && Yii::$app->user->login($model)) {
                    $model->status = Module::STATUS_ACTIVE;
                    $model->setLastLogin();
                    $event->isActivated = true;
                    $event->message = Yii::t(
                        'user',
                        'The user <b>{user}</b> was registered successfully. You have been logged in.',
                        ['user' => $model->username]
                    );
                } else {
                    $timeLeft = Module::timeLeft('activation', $model->getActivationKeyExpiry());
                    if ($model->sendEmail('activation', $timeLeft)) {
                        $event->message = Yii::t(
                            'user',
                            'Instructions for activating your account has been sent to your email <b>{email}</b>. {timeLeft}',
                            ['email' => $model->email, 'timeLeft' => $timeLeft]
                        );
                    } else {
                        $event->flashType = 'warning';
                        $event->message = Yii::t(
                            'user',
                            'Could not send activation instructions to your email <b>{email}</b>. Retry again later.',
                            ['email' => $model->email]
                        );
                    }
                }
                $event->handled = false; // reuse event object
                $m->trigger(Module::EVENT_REGISTER_COMPLETE, $event);
                static::setFlash($event);
                return $this->eventRedirect($event, $this->goHome());
            }
        }
        return $this->display($viewFile, [
            'model' => $model,
            'hasSocialAuth' => $hasSocialAuth,
            'authAction' => $authAction,
            'registerTitle' => Yii::t('user', 'Register'),
            'authTitle' => Yii::t('user', 'Or Login Using'),
            'type' => $type
        ]);
    }

    /**
     * Requests password reset.
     *
     * @return string|\yii\web\Response
     */
    public function actionRecovery()
    {
        /**
         * @var RecoveryForm $model
         * @var User         $class
         * @var User         $user
         */
        $class = $this->fetchModel(Module::MODEL_RECOVERY);
        $model = new $class();
        $event = new RecoveryEvent;
        $event->model = $model;
        $this->_module->trigger(Module::EVENT_RECOVERY_BEGIN, $event);
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = static::tranInit($event);
            try {
                $class = $this->fetchModel(Module::MODEL_USER);
                $user = $class::findByEmail($model->email);
                $proceed = true;
                $expiry = $user->getResetKeyExpiry();
                if (!$class::isKeyValid($user->reset_key, $expiry)) {
                    $user->scenario = Module::SCN_RECOVERY;
                    $user->generateResetKey();
                    $proceed = $user->save();
                }
                $timeLeft = Module::timeLeft('reset', $expiry);
                if ($proceed && $user->sendEmail('recovery', $timeLeft)) {
                    $event->flashType = 'success';
                    $event->message = Yii::t(
                        'user',
                        'Check your email for further instructions to reset your password. {timeLeft}',
                        ['timeLeft' => $timeLeft]
                    );
                    $event->handled = false;
                    $this->_module->trigger(Module::EVENT_RECOVERY_COMPLETE, $event);
                    static::setFlash($event);
                    static::tranCommit($transaction);
                    return $this->eventRedirect($event, $this->goHome());
                } else {
                    $event->flashType = 'error';
                    $event->message = Yii::t(
                        'user',
                        'Sorry, the password cannot be reset for the email provided. Retry again later.'
                    );
                    $this->_module->trigger(Module::EVENT_RECOVERY_COMPLETE, $event);
                    throw new Exception('Error resetting password');
                }

            } catch (Exception $e) {
                static::tranRollback($transaction);
                $this->raise($e, $event);
            }
        }
        static::setFlash($event);
        return $this->display($event->viewFile ? $event->viewFile : Module::VIEW_RECOVERY, [
            'model' => $model,
        ]);
    }

    /**
     * Change password for currently logged in user
     *
     * @return string|\yii\web\Response
     */
    public function actionPassword()
    {
        /**
         * @var User $model
         */
        $model = Yii::$app->user->identity;
        $model->scenario = Module::SCN_CHANGEPASS;
        $event = new LogoutEvent;
        $event->model = $model;
        $this->_module->trigger(Module::EVENT_PASSWORD_BEGIN, $event);
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = static::tranInit($event);
            try {
                $model->setPassword($model->password_new);
                $model->save(false);
                $event->flashType = 'success';
                $event->message = Yii::t('user', 'The password was changed successfully.');
                $event->handled = false;
                $this->_module->trigger(Module::EVENT_PASSWORD_COMPLETE, $event);
                static::tranCommit($transaction);
                static::setFlash($event);
                return $this->eventRedirect($event, $this->fetchUrl(Module::ACTION_PROFILE_INDEX, false));
            } catch (Exception $e) {
                static::tranRollback($transaction);
            }
        }
        return $this->display($event->viewFile ? $event->viewFile : Module::VIEW_PASSWORD, [
            'model' => $model,
        ]);
    }

    /**
     * Activates user account
     *
     * @param string $key the activation auth key
     *
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionActivate($key)
    {
        $model = $this->getUserByKey('auth', $key);
        if ($model === null) {
            throw new NotFoundHttpException(Yii::t('user', 'The activation link is invalid or expired'));
        }
        $model->scenario = Module::SCN_ACTIVATE;
        $model->status = Module::STATUS_ACTIVE;
        $model->password_reset_on = call_user_func($this->_module->now);
        $model->reset_key = null;
        $event = new ActivateEvent;
        $event->model = $model;
        $this->_module->trigger(Module::EVENT_ACTIVATE_BEGIN, $event);
        $event->handled = false;
        $transaction = static::tranInit($event);
        try {
            if ($model->save()) {
                $event->result = true;
                $event->flashType = 'success';
                $event->message = Yii::t('user', 'The account was activated successfully. You can proceed to login.');
                $this->_module->trigger(Module::EVENT_ACTIVATE_COMPLETE, $event);
                if (!$event->redirectUrl) {
                    $event->redirectUrl = $this->fetchUrl(Module::ACTION_LOGIN);
                }
                static::tranCommit($transaction);
            } else {
                $event->result = false;
                $event->flashType = 'error';
                $event->message = Yii::t(
                    'user',
                    'Could not activate the account. Please try again later or contact us.'
                );
                $this->_module->trigger(Module::EVENT_ACTIVATE_COMPLETE, $event);
                throw new Exception('Error activating account');
            }
        } catch (Exception $e) {
            static::tranRollback($transaction);
            $this->raise($e, $event);
        }
        static::setFlash($event);
        if ($event->redirectUrl) {
            return $this->eventRedirect($event, $this->fetchUrl(Module::ACTION_LOGIN), false);
        }
        return $this->goHome();
    }

    /**
     * Reset user account password
     *
     * @param string $key the reset key
     *
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionReset($key)
    {
        $model = $this->getUserByKey('reset', $key);
        if ($model === null) {
            throw new NotFoundHttpException(Yii::t('user', 'The password reset link is invalid or expired'));
        }
        $model->scenario = Module::SCN_RESET;
        $event = new ResetEvent;
        $event->model = $model;
        $this->_module->trigger(Module::EVENT_PASSWORD_BEGIN, $event);
        $event->handled = false;
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = static::tranInit($event);
            try {
                $model->setPassword($model->password_new);
                $model->unlock();
                $model->reset_key = null;
                if ($model->save()) {
                    $event->result = true;
                    $event->flashType = 'success';
                    $event->message = Yii::t(
                        'user',
                        'The password was reset successfully. You can proceed to login with your new password.'
                    );
                    $this->_module->trigger(Module::EVENT_RESET_COMPLETE, $event);
                    if (!$event->redirectUrl) {
                        $event->redirectUrl = $this->fetchUrl(Module::ACTION_LOGIN);
                    }
                    static::tranCommit($transaction);
                } else {
                    $event->result = false;
                    $event->flashType = 'error';
                    $event->message = Yii::t(
                        'user',
                        'Could not reset the password. Please try again later or contact us.'
                    );
                    $this->_module->trigger(Module::EVENT_RESET_COMPLETE, $event);
                }
            } catch (Exception $e) {
                static::tranRollback($transaction);
            }
        }
        static::setFlash($event);
        if ($event->redirectUrl) {
            return $this->eventRedirect($event, $this->fetchUrl(Module::ACTION_LOGIN), false);
        }
        return $this->display($event->viewFile ? $event->viewFile : Module::VIEW_RESET, [
            'model' => $model,
        ]);
    }


    /**
     * Confirm new email change for user
     *
     * @param string $key the email change key
     *
     * @return string|\yii\web\Response
     * @throws NotFoundHttpException
     */
    public function actionNewemail($key)
    {
        $model = $this->getUserByKey('email_change', $key);
        if ($model === null) {
            throw new NotFoundHttpException(Yii::t('user', 'The email change confirmation link is invalid or expired'));
        }
        $model->scenario = Module::SCN_NEWEMAIL;
        $event = new NewemailEvent;
        $event->model = $model;
        $this->_module->trigger(Module::EVENT_NEWEMAIL_BEGIN, $event);
        if ($model->load(Yii::$app->request->post()) && $model->validate()) {
            $transaction = static::tranInit($event);
            try {
                $model->email = $model->email_new;
                $model->email_new = null;
                $model->email_change_key = null;
                if ($model->save()) {
                    $event->result = true;
                    $event->flashType = 'success';
                    $event->message = Yii::t('user', 'The email address was changed successfully.');
                    $this->_module->trigger(Module::EVENT_NEWEMAIL_COMPLETE, $event);
                    $event->redirectUrl = $event->redirectUrl ? $event->redirectUrl :
                        $this->fetchUrl(Module::ACTION_PROFILE_INDEX);
                } else {
                    $event->result = false;
                    $event->flashType = 'error';
                    $event->message = Yii::t(
                        'user',
                        'Could not confirm the new email address. Please try again later or contact us.'
                    );
                    throw new Exception('Error saving new email');
                }
            } catch (Exception $e) {
                static::tranRollback($transaction);
                $this->raise($e, $event);
            }
            $this->_module->trigger(Module::EVENT_NEWEMAIL_COMPLETE, $event);
        }
        static::setFlash($event);
        return $this->eventRedirect(
            $event,
            $this->display($event->viewFile ? $event->viewFile : Module::VIEW_NEWEMAIL, [
                'model' => $model,
            ])
        );
    }

    /**
     * Gets user model by key type
     *
     * @param string $type the type of key to fetch
     * @param string $key the key value
     *
     * @return User the user model if found (or null)
     */
    protected function getUserByKey($type, $key)
    {
        /**
         * @var User $class
         */
        if ($type !== 'auth' && $type !== 'reset' && $type !== 'email_change') {
            return null;
        }
        $class = $this->fetchModel(Module::MODEL_USER);
        $attribute = "{$type}_key";
        return $class::findByKey($attribute, $key);
    }
}
