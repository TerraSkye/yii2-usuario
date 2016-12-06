<?php
namespace Da\User\Controller;

use Da\User\Event\FormEvent;
use Da\User\Event\ResetPasswordEvent;
use Da\User\Factory\MailFactory;
use Da\User\Form\RecoveryForm;
use Da\User\Model\Token;
use Da\User\Query\TokenQuery;
use Da\User\Query\UserQuery;
use Da\User\Service\PasswordRecoveryService;
use Da\User\Service\ResetPasswordService;
use Da\User\Traits\ContainerTrait;
use Da\User\Traits\ModuleTrait;
use Da\User\Validator\AjaxRequestModelValidator;
use Yii;
use yii\base\Module;
use yii\filters\AccessControl;
use yii\web\Controller;
use yii\web\NotFoundHttpException;

class RecoveryController extends Controller
{
    use ModuleTrait;
    use ContainerTrait;

    protected $userQuery;
    protected $tokenQuery;

    /**
     * RecoveryController constructor.
     *
     * @param string $id
     * @param Module $module
     * @param UserQuery $userQuery
     * @param TokenQuery $tokenQuery
     * @param array $config
     */
    public function __construct($id, Module $module, UserQuery $userQuery, TokenQuery $tokenQuery, array $config)
    {
        $this->userQuery = $userQuery;
        $this->tokenQuery = $tokenQuery;
        parent::__construct($id, $module, $config);
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
            'access' => [
                'class' => AccessControl::className(),
                'rules' => [
                    [
                        'allow' => true,
                        'actions' => ['request', 'reset'],
                        'roles' => ['?']
                    ],
                ],
            ],
        ];
    }

    /**
     * Displays / handles user password recovery request.
     *
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionRequest()
    {
        if (!$this->getModule()->allowPasswordRecovery) {
            throw new NotFoundHttpException();
        }

        /** @var RecoveryForm $form */
        $form = $this->make(RecoveryForm::class, ['scenario' => RecoveryForm::SCENARIO_REQUEST]);

        $event = $this->make(FormEvent::class, [$form]);

        $this->make(AjaxRequestModelValidator::class, $form)->validate();

        $this->trigger(FormEvent::EVENT_BEFORE_REQUEST, $event);

        if ($form->load(Yii::$app->request->post())) {
            $mailService = MailFactory::makeRecoveryMailerService($form->email);

            if ($this->make(PasswordRecoveryService::class, [$form->email, $mailService])->run()) {
                $this->trigger(FormEvent::EVENT_AFTER_REQUEST, $event);

                return $this->render(
                    'message',
                    [
                        'title' => Yii::t('user', 'Recovery message sent'),
                        'module' => $this->getModule(),
                    ]
                );
            }
        }

        return $this->render('request', ['model' => $form,]);
    }

    /**
     * Displays / handles user password reset.
     *
     * @param $id
     * @param $code
     *
     * @return string
     * @throws NotFoundHttpException
     */
    public function actionReset($id, $code)
    {
        if (!$this->getModule()->allowPasswordRecovery) {
            throw new NotFoundHttpException();
        }
        /** @var Token $token */
        $token = $this->tokenQuery->whereIsRecoveryType($id, $code)->one();
        /** @var ResetPasswordEvent $event */
        $event = $this->make(ResetPasswordEvent::class, [$token]);

        $this->trigger(ResetPasswordEvent::EVENT_BEFORE_TOKEN_VALIDATE, $event);

        if ($token === null || $token->getIsExpired() || $token->user === null) {
            Yii::$app->session->setFlash(
                'danger',
                Yii::t('user', 'Recovery link is invalid or expired. Please try requesting a new one.')
            );

            return $this->render(
                'message',
                [
                    'title' => Yii::t('user', 'Invalid or expired link'),
                    'module' => $this->getModule(),
                ]
            );
        }

        /** @var RecoveryForm $form */
        $form = $this->make(RecoveryForm::class, ['scenario' => RecoveryForm::SCENARIO_RESET]);
        $event = $event->updateForm($form);

        $this->make(AjaxRequestModelValidator::class, [$form])->validate();

        if ($form->load(Yii::$app->getRequest()->post())) {
            if ($this->make(ResetPasswordService::class, [$form->password, $token->user])->run()) {
                $this->trigger(ResetPasswordEvent::EVENT_AFTER_RESET, $event);

                return $this->render(
                    'message',
                    [
                        'title' => Yii::t('user', 'Password has been changed'),
                        'module' => $this->getModule(),
                    ]
                );
            }
        }

        return $this->render('reset', ['model' => $form,]);
    }
}