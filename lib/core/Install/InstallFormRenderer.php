<?php

namespace MajorDoMo\Core\Install;

/**
 * Server-side renderer for schema fields.
 *
 * It uses the canonical MDJUI components, but deliberately avoids the native
 * `required` attribute: the wizard has several hidden steps and native HTML
 * validation tries to focus controls that are not visible. Client-side checking
 * and server-side validation cover the same rules without that trap.
 */
final class InstallFormRenderer
{
    /**
     * @param array<string,mixed> $field
     * @param string $value
     * @param array{errors?:array<string,string>,secrets?:array<string,bool>} $context
     */
    public static function field(array $field, $value, array $context = array()): string
    {
        $id = (string)($field['id'] ?? '');
        if ($id === '') {
            return '';
        }
        $errors = isset($context['errors']) && is_array($context['errors']) ? $context['errors'] : array();
        $secrets = isset($context['secrets']) && is_array($context['secrets']) ? $context['secrets'] : array();
        $error = isset($errors[$id]) ? (string)$errors[$id] : '';
        $value = is_scalar($value) ? (string)$value : '';
        $type = (string)($field['type'] ?? 'text');
        $width = in_array(($field['width'] ?? 'half'), array('half', 'full'), true) ? $field['width'] : 'half';
        $depends = isset($field['depends']) && is_array($field['depends']) ? $field['depends'] : null;
        $condition = isset($field['condition']) ? (string)$field['condition'] : '';
        $isSecret = !empty($field['secret']);
        $hasSecret = $isSecret && !empty($secrets[$id]);
        $inputValue = $isSecret ? '' : $value;

        if ($isSecret && $hasSecret && $inputValue === '') {
            $field['placeholder'] = '•••••••• (сохранён)';
        }

        $wrapperAttrs = array(
            'class' => 'md-install-field md-install-field--' . $width,
        );
        if ($depends !== null && isset($depends['field'], $depends['value'])) {
            $wrapperAttrs['data-install-depends'] = json_encode(
                array('field' => (string)$depends['field'], 'value' => (string)$depends['value']),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        }
        if ($condition !== '') {
            $wrapperAttrs['data-install-condition'] = $condition;
        }

        $html = '<div' . \MDJUI::attrs($wrapperAttrs) . '>';
        if ($type === 'switch') {
            $checked = self::isOn($value);
            $html .= \MDJUI::switchControl(array(
                'name' => $id,
                'id' => 'install-field-' . $id,
                'value' => '1',
                'label' => (string)($field['label'] ?? $id),
                'description' => (string)($field['description'] ?? ''),
                'checked' => $checked,
                'attrs' => array('data-install-required' => !empty($field['required']) ? '1' : null),
            ));
            if ($error !== '') {
                $html .= '<div class="md-field__error" role="alert">' . \MDJUI::icon('exclamation-circle') . '<span>' . \MDJUI::e($error) . '</span></div>';
            }
        } elseif ($type === 'choice') {
            $control = \MDJUI::choiceGroup(array(
                'name' => $id,
                'selected' => $value,
                'options' => self::normalizeChoiceOptions($field['options'] ?? array()),
                'attrs' => array('data-install-required' => !empty($field['required']) ? '1' : null),
            ));
            $html .= self::wrapField($field, $id, $control, $error, $hasSecret);
        } else {
            $control = self::control($field, $inputValue, $id);
            $html .= self::wrapField($field, $id, $control, $error, $hasSecret);
            if ($isSecret && $hasSecret) {
                $html .= \MDJUI::checkbox(array(
                    'name' => $id . '__clear',
                    'id' => 'install-field-' . $id . '-clear',
                    'label' => 'Очистить сохранённое значение',
                    'description' => 'Поставьте галочку, если нужно записать пустой пароль или токен.',
                    'class' => 'md-install-clear',
                ));
            }
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * @param array<string,mixed> $field
     */
    public static function displayValue(array $field, $value): string
    {
        $value = is_scalar($value) ? (string)$value : '';
        $type = (string)($field['type'] ?? 'text');
        if (!empty($field['secret'])) {
            return $value === '' ? 'не задано' : '•••••••• (сохранено)';
        }
        if ($type === 'switch') {
            return self::isOn($value) ? 'включено' : 'выключено';
        }
        if ($type === 'select' || $type === 'choice') {
            $options = $field['options'] ?? array();
            if ($type === 'choice') {
                foreach ((array)$options as $option) {
                    if (is_array($option) && (string)($option['value'] ?? '') === $value) {
                        return (string)($option['label'] ?? $value);
                    }
                }
            } elseif (is_array($options)) {
                if (isset($options[$value]) && is_scalar($options[$value])) {
                    return (string)$options[$value];
                }
                foreach ($options as $key => $label) {
                    if (is_array($label) && (string)($label['value'] ?? $key) === $value) {
                        return (string)($label['label'] ?? $value);
                    }
                }
            }
            return $value === '' ? 'не задано' : $value;
        }
        if ($value === '') {
            return $type === 'number' ? 'по умолчанию' : 'не задано';
        }
        if ($type === 'password') {
            return '••••••••';
        }
        return $value;
    }

    public static function timezoneDatalist(): string
    {
        $html = '<datalist id="md-install-timezones">';
        foreach (InstallSchema::timezoneOptions() as $zone => $label) {
            $html .= '<option value="' . \MDJUI::e($zone) . '">' . \MDJUI::e($label) . '</option>';
        }
        return $html . '</datalist>';
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function control(array $field, string $value, string $id): string
    {
        $type = (string)($field['type'] ?? 'text');
        $base = array(
            'name' => $id,
            'id' => 'install-field-' . $id,
            'value' => $value,
            'placeholder' => (string)($field['placeholder'] ?? ''),
            'autocomplete' => (string)($field['autocomplete'] ?? 'off'),
            'wrap' => false,
            'attrs' => array(
                'data-install-required' => !empty($field['required']) ? '1' : null,
                'inputmode' => $type === 'number' ? 'decimal' : null,
            ),
        );

        if ($type === 'select') {
            return \MDJUI::select(array(
                'name' => $id,
                'id' => 'install-field-' . $id,
                'selected' => $value,
                'options' => (array)($field['options'] ?? array()),
                'placeholder' => (string)($field['placeholder'] ?? ''),
                'attrs' => array('data-install-required' => !empty($field['required']) ? '1' : null),
            ));
        }
        if ($type === 'choice') {
            return \MDJUI::choiceGroup(array(
                'name' => $id,
                'selected' => $value,
                'options' => self::normalizeChoiceOptions($field['options'] ?? array()),
                'attrs' => array('data-install-required' => !empty($field['required']) ? '1' : null),
            ));
        }
        if ($type === 'textarea') {
            return \MDJUI::textarea(array_merge($base, array(
                'rows' => (int)($field['rows'] ?? 4),
            )));
        }

        $inputType = 'text';
        if ($type === 'number') {
            $inputType = 'number';
        } elseif ($type === 'password') {
            $inputType = 'password';
        } elseif ($type === 'url') {
            $inputType = 'url';
        }
        if ($type === 'timezone') {
            $base['attrs']['list'] = 'md-install-timezones';
        }
        if ($type === 'number') {
            if (isset($field['min'])) {
                $base['min'] = $field['min'];
            }
            if (isset($field['max'])) {
                $base['max'] = $field['max'];
            }
            if (isset($field['step_attr'])) {
                $base['step'] = $field['step_attr'];
            }
        }
        if (isset($field['icon'])) {
            $base['icon'] = (string)$field['icon'];
        }
        if (!empty($field['password_toggle'])) {
            $base['password_toggle'] = true;
        }
        if (isset($field['maxlength'])) {
            $base['attrs']['maxlength'] = (int)$field['maxlength'];
        }
        $base['type'] = $inputType;
        return \MDJUI::input($base);
    }

    /**
     * @param array<string,mixed> $field
     */
    private static function wrapField(array $field, string $id, string $control, string $error, bool $hasSecret): string
    {
        $html = \MDJUI::field(array(
            'label' => (string)($field['label'] ?? $id),
            'for' => 'install-field-' . $id,
            'required' => !empty($field['required']),
            'optional' => empty($field['required']),
            'error' => $error,
        ), $control);

        $description = (string)($field['description'] ?? '');
        if ($hasSecret) {
            $description = trim($description . ' Оставьте пустым, чтобы сохранить текущее значение.');
        }
        if ($error === '' && $description !== '') {
            $html .= '<div class="md-install-field__note">' . \MDJUI::e($description) . '</div>';
        }
        return $html;
    }

    /**
     * @param array<int|string,mixed> $options
     * @return array<int,array<string,string>>
     */
    private static function normalizeChoiceOptions(array $options): array
    {
        $normalized = array();
        foreach ($options as $key => $option) {
            if (is_array($option)) {
                $normalized[] = array(
                    'value' => (string)($option['value'] ?? $key),
                    'label' => (string)($option['label'] ?? $option['value'] ?? $key),
                    'description' => (string)($option['description'] ?? ''),
                    'icon' => (string)($option['icon'] ?? ''),
                );
            } else {
                $normalized[] = array(
                    'value' => (string)$key,
                    'label' => (string)$option,
                    'description' => '',
                    'icon' => '',
                );
            }
        }
        return $normalized;
    }

    private static function isOn(string $value): bool
    {
        return in_array(strtolower(trim($value)), array('1', 'true', 'yes', 'on', 'да'), true);
    }
}
