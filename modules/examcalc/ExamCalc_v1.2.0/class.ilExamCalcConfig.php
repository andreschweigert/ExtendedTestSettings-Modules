<?php

class ilExamCalcConfig
{
    protected ilSetting $settings;

    public function __construct(string $namespace)
    {
        $this->settings = new ilSetting($namespace);
    }

    public function get(string $key, string $default = ""): string
    {
        return $this->settings->get($key, $default);
    }

    public function set(string $key, string $value): void
    {
        $this->settings->set($key, $value);
    }
}