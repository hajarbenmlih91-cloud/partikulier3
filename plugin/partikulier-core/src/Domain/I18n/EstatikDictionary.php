<?php
/**
 * Repli de traduction pour les chaînes du plugin Estatik absentes de ses
 * propres catalogues (popup d'authentification intégrée au parcours
 * connexion). Classe de DONNÉES pure : aucun hook, aucune écriture.
 *
 * Règle d'or : ne JAMAIS écraser une traduction existante — le repli ne
 * s'applique que lorsque gettext renvoie encore la chaîne source anglaise.
 * Le domaine traité est 'estatik', jamais 'partikulier' : le corpus gelé du
 * chrome (C2/E24) est intact.
 */
declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class EstatikDictionary
{
	/**
	 * @return array<string,array<string,string>>
	 */
	public static function translations(): array
	{
		return array(
			'ar' => array(
				'Powered by %s' => 'مدعوم من %s',
				'Powered by' => 'مدعوم من',
				'By clicking the «SIGN UP» button you agree to the Terms of Use and Privacy Policy' => 'بالنقر على زر «تسجيل» فإنك توافق على شروط الاستخدام وسياسة الخصوصية.',
				'Terms of Use' => 'شروط الاستخدام',
				'Privacy Policy' => 'سياسة الخصوصية',
				'Reset password' => 'إعادة تعيين كلمة المرور',
				'Back to login' => 'العودة إلى تسجيل الدخول',
				'Change anytime' => 'يمكن تغييره في أي وقت',
				'You\'ll use it to sign in, and we\'ll use it to contact you.' => 'ستستخدمه لتسجيل الدخول، وسنستخدمه للتواصل معك.',
				'Can\'t contain the name or email address' => 'لا يمكن أن يحتوي على الاسم أو البريد الإلكتروني',
				'Sign in' => 'تسجيل الدخول',
				'Login' => 'تسجيل الدخول',
				'Email' => 'البريد الإلكتروني',
				'Password' => 'كلمة المرور',
				'By clicking the %1$s button you agree to the %2$s and %3$s' => 'بالنقر على زر %1$s فإنك توافق على %2$s و %3$s',
				'SIGN UP' => 'إنشاء حساب',
				'Reset' => 'إعادة تعيين',
			),
			'fr' => array(
				'Powered by %s' => 'Propulsé par %s',
				'Powered by' => 'Propulsé par',
				'By clicking the «SIGN UP» button you agree to the Terms of Use and Privacy Policy' => 'En cliquant sur « S’INSCRIRE », vous acceptez les conditions d’utilisation et la politique de confidentialité.',
				'Terms of Use' => 'Conditions d’utilisation',
				'Privacy Policy' => 'Politique de confidentialité',
				'Reset password' => 'Réinitialiser le mot de passe',
				'Back to login' => 'Retour à la connexion',
				'Change anytime' => 'Modifiable à tout moment',
				'You\'ll use it to sign in, and we\'ll use it to contact you.' => 'Vous l’utiliserez pour vous connecter, et nous pour vous contacter.',
				'Can\'t contain the name or email address' => 'Ne peut pas contenir le nom ou l’adresse e-mail',
				'By clicking the %1$s button you agree to the %2$s and %3$s' => 'En cliquant sur le bouton %1$s, vous acceptez %2$s et %3$s',
				'SIGN UP' => 'S’INSCRIRE',
				'Reset' => 'Réinitialiser',
			),
		);
	}

	/**
	 * Repli ciblé : uniquement si la chaîne est encore la source anglaise.
	 */
	public static function translate( string $translation, string $text, string $language ): string
	{
		if ( $translation !== $text ) {
			return $translation;
		}
		$dict = self::translations();
		return $dict[ $language ][ $text ] ?? $translation;
	}
}
