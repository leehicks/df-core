<?php
namespace DreamFactory\Core\Components;

use DreamFactory\Core\Models\Service;
use DreamFactory\Core\Utility\ServiceHandler;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Models\User;
use DreamFactory\Core\Models\EmailTemplate;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use Illuminate\Contracts\Auth\Registrar as RegistrarContract;
use DreamFactory\Core\Services\Email\BaseService as EmailService;
use Validator;

class Registrar implements RegistrarContract
{

    /**
     * Get a validator for an incoming registration request.
     *
     * @param  array $data
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    public function validator(array $data)
    {
        $userService = Service::getCachedByName('user');
        $validationRules = [
            'name'       => 'required|max:255',
            'first_name' => 'required',
            'last_name'  => 'required',
            'email'      => 'required|email|max:255|unique:user'
        ];

        if(empty($userService['config']['open_reg_email_service_id'])) {
            $validationRules['password'] = 'required|confirmed|min:6';
        }

        return Validator::make($data, $validationRules);
    }

    /**
     * Creates first admin user.
     *
     * @param  array $data
     *
     * @return User|false
     */
    public function createFirstAdmin(array $data)
    {
        $adminExists = User::whereIsActive(1)->whereIsSysAdmin(1)->exists();

        if (!$adminExists) {
            $user = User::create([
                'name'       => ArrayUtils::get($data, 'name'),
                'first_name' => ArrayUtils::get($data, 'first_name'),
                'last_name'  => ArrayUtils::get($data, 'last_name'),
                'is_active'  => 1,
                'email'      => ArrayUtils::get($data, 'email')
            ]);

            $user->password = ArrayUtils::get($data, 'password');
            $user->is_sys_admin = 1;
            $user->save();

            //Reset admin_exists flag in cache.
            User::resetAdminExists();

            return $user;
        }

        return false;
    }

    /**
     * Creates a non-admin user.
     *
     * @param array $data
     *
     * @return \DreamFactory\Core\Models\BaseModel
     * @throws \Exception
     */
    public function create(array $data)
    {
        $userService = Service::getCachedByName('user');
        $openRegEmailSvcId = $userService['config']['open_reg_email_service_id'];
        $openRegEmailTplId = $userService['config']['open_reg_email_template_id'];
        $openRegRoleId = $userService['config']['open_reg_role_id'];
        $user = User::create($data);

        if(!empty($openRegEmailSvcId)){
            $this->sendConfirmation($user, $openRegEmailSvcId, $openRegEmailTplId);
        } else if(!empty($data['password'])){
            $user->password = $data['password'];
            $user->save();
        }

        if(!empty($openRegRoleId)){
            User::applyDefaultUserAppRole($user, $openRegRoleId);
        }

        return $user;
    }

    /**
     * @param           $user User
     * @param           $emailServiceId
     * @param           $emailTemplateId
     * @param bool|true $deleteOnError
     *
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    protected static function sendConfirmation($user, $emailServiceId, $emailTemplateId, $deleteOnError=true)
    {
        try {
            if (empty($emailServiceId)) {
                throw new InternalServerErrorException('No email service configured for user invite. See system configuration.');
            }

            if (empty($emailTemplateId)) {
                throw new InternalServerErrorException("No default email template for user invite.");
            }

            /** @var EmailService $emailService */
            $emailService = ServiceHandler::getServiceById($emailServiceId);
            $emailTemplate = EmailTemplate::find($emailTemplateId);

            if (empty($emailTemplate)) {
                throw new InternalServerErrorException("No data found in default email template for user invite.");
            }

            try {
                $email = $user->email;
                $code = \Hash::make($email);
                $user->confirm_code = base64_encode($code);
                $user->save();

                $data = [
                    'to'           => $email,
                    'subject'      => 'Welcome to DreamFactory',
                    'first_name'   => $user->first_name,
                    'last_name'    => $user->last_name,
                    'confirm_code' => $user->confirm_code,
                    'display_name' => $user->name,
                    'from_name'    => 'DreamFactory 2.0'
                ];
            } catch (\Exception $e) {
                throw new InternalServerErrorException("Error creating user confirmation.\n{$e->getMessage()}",
                    $e->getCode());
            }

            $emailService->sendEmail($data, $emailTemplate->body_text, $emailTemplate->body_html);
        } catch (\Exception $e){
            if ($deleteOnError) {
                $user->delete();
            }
            throw new InternalServerErrorException("Error processing user confirmation.\n{$e->getMessage()}", $e->getCode());
        }
    }
}
