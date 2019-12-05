<?php
namespace Konnco\Transeloquent;

use Illuminate\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;

trait Transeloquent
{
    /**
     * Current model locale.
     *
     * @var null
     */
    protected $currentLocale = null;

    /**
     * Transeloquent variable container.
     *
     * @var array
     */
    protected $transeloquent = [
        'attributes'   => [],
        'translations' => [],
    ];

    /**
     * Booting process to registering eloquent events.
     */
    public static function bootTranseloquent(): void
    {
        static::saving(function (Model $model) {
            return $model->saveTranslations();
        });
        static::deleting(function (Model $model) {
            return $model->deleteTranslations();
        });

        static::retrieved(function (Model $model) {
            $translated = [];
            $translate = $model->transeloquent($model->getCurrentLocale())->get();
            $translate->each(function ($field) use (&$translated) {
                $translated[$field->key] = $field->value;
            });

            $model->setRawTranslatedAttributes($translated);
            $model->getAvailableTranslations();
        });
    }

    /**
     * fetch Available Translations.
     */
    public function getAvailableTranslations()
    {
        return collect($this->transeloquent()->select('locale')->groupBy('locale')->get()->toArray())->values();
    }

    /**
     * set Default Locale.
     *
     * @param $lang
     */
    public function setLocale($lang)
    {
        $this->currentLocale = $lang;
        return $this;
    }

    /**
     * Setting Raw Translation attributes.
     *
     * @param array $translates
     */
    public function setRawTranslatedAttributes($translates = [])
    {
        $this->transeloquent['attributes'] = $translates;
    }

    /**
     * Get current locale.
     *
     * @return string
     */
    private function getCurrentLocale()
    {
        return $this->currentLocale ?? App::getLocale();
    }

    /**
     * Get default locale.
     *
     * @return Repository|mixed
     */
    public function getDefaultLocale()
    {
        return config('transeloquent.locale');
    }

    private function getTranseloquentModel()
    {
        return config('transeloquent.model');
    }

    public function transeloquent($locale = null)
    {
        $transeloquentObject = $this->morphMany($this->getTranseloquentModel(), 'translatable');
        if ($locale != null) {
            $transeloquentObject->where('locale', $locale);
        }

        return $transeloquentObject;
    }

    /**
     * Override parents functions to get single attributes.
     *
     * @param $key
     *
     * @return mixed
     */
    public function getAttribute($key)
    {
        $defaultLocale = $this->getDefaultLocale();
        $currentLocale = $this->getCurrentLocale();

        if ($defaultLocale == $currentLocale) {
            return parent::getAttribute($key);
        } else {
            return @$this->transeloquent['attributes'][$key] ?? parent::getAttribute($key);
        }
    }

    /**
     * Check Translation Exists.
     *
     * @param $lang
     */
    public function translationExist($lang)
    {
        return array_search($lang, $this->transeloquent['translations']) >= 0;
    }

    /**
     * Set Excluded these Fields from Translate.
     *
     * @return Collection
     */
    private function getTranslateExcept()
    {
        $attributes = collect($this->attributesToArray());
        foreach (array_merge($this->translateExcept ?? [], ['id', 'created_at', 'updated_at']) as $value) {
            $filtered = $attributes->forget($value);
        }

        return $filtered;
    }

    /**
     * Set Only these Fields to Translate.
     *
     * @return Collection
     */
    private function getTranslateOnlyAttributes()
    {
        $attributes = collect($this->attributesToArray());
        foreach ($this->translateOnly ?? [] as $value) {
            $filtered = $attributes->only($value);
        }

        return $filtered;
    }

    /**
     * Saving Translation.
     *
     * @return bool
     */
    public function saveTranslations()
    {
        $defaultLocale = $this->getDefaultLocale();
        $currentLocale = $this->getCurrentLocale();

        if ($defaultLocale != $currentLocale) {
            $attributes = isset($this->translateOnly) ? $this->getTranslateOnlyAttributes() : $this->getTranslateExcept();

            foreach ($attributes as $key => $attribute) {
                if ($attribute != null) {
                    $translate = $this->transeloquent($this->getCurrentLocale())->where('key', $key)->first();
                    if ($translate == null) {
                        $transeloquentModel = $this->getTranseloquentModel();
                        $translate = new $transeloquentModel;
                    }
                    $translate->locale = $this->getCurrentLocale();
                    $translate->key = $key;
                    $translate->value = $attribute;
                    $translate->save();
                    $this->transeloquent($this->getCurrentLocale())->save($translate);
                }
            }

            $this->setRawTranslatedAttributes($attributes);
            $this->setRawAttributes($this->getOriginal());
        }

        return true;
    }

    /**
     * Delete Translation.
     *
     * @return mixed
     */
    public function deleteTranslations()
    {
        if (!$this->isSoftDelete()) {
            return $this->transeloquent()->delete();
        }
    }

    /**
     * Checking Model is softdeleting or not.
     *
     * @return bool
     */
    public function isSoftDelete()
    {
        return in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses($this)) && !$this->forceDeleting;
    }
}
