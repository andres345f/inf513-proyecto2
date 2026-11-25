import { useId } from 'react'
import { CircleAlert } from 'lucide-react'
import './Campo.css'

/**
 * Campo de formulario con etiqueta, ayuda y mensaje de error accesibles.
 */
export default function CampoTexto({ etiqueta, nombre, error, ayuda, obligatorio = false, opcional = false, tipo = 'text', ...resto }) {
  const id = useId()
  const idAyuda = ayuda ? `${id}-ayuda` : undefined
  const idError = error ? `${id}-error` : undefined

  return (
    <div className={`campo${error ? ' campo--error' : ''}`}>
      <label className="campo__etiqueta" htmlFor={id}>
        {etiqueta}{opcional && <small className="campo__opcional"> (opcional)</small>}
        {obligatorio && <span className="campo__obligatorio" aria-hidden="true"> *</span>}
      </label>
      <input
        id={id}
        name={nombre}
        type={tipo}
        className="campo__control"
        aria-invalid={Boolean(error)}
        aria-required={obligatorio}
        aria-describedby={[idAyuda, idError].filter(Boolean).join(' ') || undefined}
        {...resto}
      />
      {ayuda && <p id={idAyuda} className="campo__ayuda">{ayuda}</p>}
      {error && <p id={idError} className="campo__mensaje-error" role="alert"><CircleAlert size={15} aria-hidden="true" /> {error}</p>}
    </div>
  )
}
