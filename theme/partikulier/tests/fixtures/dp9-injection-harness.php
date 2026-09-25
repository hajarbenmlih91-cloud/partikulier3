<?php
/**
 * HARNais d'injection — suites dp9 (SE-044 / DP-9, exigence CP3 §3.1).
 * TEST SEULEMENT — ce mu-plugin vit dans tests/fixtures/ du dépôt ; la CI et
 * le laboratoire le copient dans wp-content/mu-plugins/ pour la SEULE durée
 * des suites dp9, puis le retirent. Il n'est PAS livré avec le code
 * applicatif (harnais d'injection réutilisable exigé par R1 §5.3, famille F5).
 *
 * Rôle :
 *  1. définir la constante de test PK_DP9_TEST_INJECTION — sans elle, la
 *     couture du moteur (Partikulier_Listing_Transitions::injected_defect)
 *     est INERTE PAR CONSTRUCTION (le filtre n'est même pas consulté) ;
 *  2. écouter le filtre 'pk_dp9_defect_injection' et appliquer le défaut
 *     armé via l'option 'pk_dp9_injection_armee' :
 *     {point: after_source_write|during_propagation|before_invalidation,
 *      source_id?, variant_id?, persistent?, pose_marqueur_sur?, marqueur?}
 *
 * Modes :
 *  - défaut simple : le défaut s'applique UNE SEULE fois (auto-désarmement
 *    au déclenchement) — la reprise s'exécute sans défaut, exactement comme
 *    une panne passagère ;
 *  - persistent (classe « échec persistant », R1 §4.2 f) : l'option N'est
 *    PAS supprimée au déclenchement — la même étape échoue à nouveau à
 *    chaque renvoi jusqu'à désarmement explicite ;
 *  - pose_marqueur_sur + marqueur (exigence CP3 §3.2-a, entrelacement) :
 *    au point armé — DANS la fenêtre précontrôle→écriture — le harnais pose
 *    le marqueur administratif donné sur la variante désignée (simulant une
 *    modération concurrente posée dans la fenêtre), puis NE déclenche AUCUN
 *    défaut : la propagation doit ensuite ignorer la variante (marqueur
 *    préservé) et le consigner.
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

if ( ! defined( 'PK_DP9_TEST_INJECTION' ) ) {
        define( 'PK_DP9_TEST_INJECTION', true );
}

add_filter( 'pk_dp9_defect_injection', function ( $injection, $context ) {
        $armee = get_option( 'pk_dp9_injection_armee', null );
        if ( ! is_array( $armee ) || empty( $armee['point'] ) ) {
                return null; // rien d'armé — comportement normal.
        }
        if ( $armee['point'] !== $context['point'] ) {
                return null;
        }
        if ( ! empty( $armee['source_id'] ) && (int) $armee['source_id'] !== (int) $context['source_id'] ) {
                return null;
        }
        if ( ! empty( $armee['variant_id'] ) && (int) $armee['variant_id'] !== (int) $context['variant_id'] ) {
                return null;
        }

        $persistent = ! empty( $armee['persistent'] );

        // Entrelacement simulé : la modération tombe DANS la fenêtre (au point armé),
        // sans défaut — la propagation doit préserver le marqueur fraîchement posé.
        // Le point armé est 'after_source_write' : APRÈS l'écriture de la source et
        // le précontrôle du groupe, AVANT la boucle de propagation — c'est la
        // fenêtre exacte où une modération concurrente peut survenir.
        if ( ! empty( $armee['pose_marqueur_sur'] ) && ! empty( $armee['marqueur'] ) ) {
                $deja = (string) get_post_meta( (int) $armee['pose_marqueur_sur'], '_pk_status', true );
                if ( $deja !== (string) $armee['marqueur'] ) {
                        update_post_meta( (int) $armee['pose_marqueur_sur'], '_pk_status', (string) $armee['marqueur'] );
                }
                if ( empty( $persistent ) ) {
                        delete_option( 'pk_dp9_injection_armee' );
                }
                return null; // aucun défaut : la transition continue, la propagation doit ignorer.
        }

        // Défaut (simple ou persistant) : auto-désarmement sauf mode persistant.
        if ( ! $persistent ) {
                delete_option( 'pk_dp9_injection_armee' );
        }
        return array( $context['point'] => true );
}, 10, 2 );
