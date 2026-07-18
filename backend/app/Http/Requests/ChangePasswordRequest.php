<?php

namespace App\Http\Requests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as ValidatorFactory;
use Illuminate\Support\MessageBag;

class ChangePasswordRequest
{
    public const NEW_PASSWORD_MIN = 8;

    private Request $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public static function make(Request $request): self
    {
        return new self($request);
    }

    /**
     * @return array{current_password: string, new_password: string}|array{errors: MessageBag}
     */
    public function validate(): array
    {
        $validator = ValidatorFactory::make($this->request->all(), [
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:' . self::NEW_PASSWORD_MIN . '|confirmed',
        ]);

        if ($validator->fails()) {
            return ['errors' => $validator->errors()];
        }

        return [
            'current_password' => (string) $this->request->input('current_password'),
            'new_password' => (string) $this->request->input('new_password'),
        ];
    }
}
