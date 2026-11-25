import './Campo.css'

/**
 * Mensaje de error bajo un control de formulario. El control lo enlaza con
 * aria-describedby (ver atributosError en utils/validaciones) y el lector de pantalla lo anuncia.
 */
export default function MensajeCampo({ id, error }) {
  if (!error) return null
  return <span id={id} className="campo__mensaje-error" role="alert">{error}</span>
}
