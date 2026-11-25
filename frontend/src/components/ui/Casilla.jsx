import { useId } from 'react'
import './Campo.css'

/** Casilla de verificación con mensaje de error accesible. */
export default function Casilla({ nombre, children, error, ...resto }) {
  const id = useId()
  const idError = error ? `${id}-error` : undefined

  return (
    <div className={`campo campo--casilla${error ? ' campo--error' : ''}`}>
      <div className="campo__fila">
        <input id={id} name={nombre} type="checkbox" className="campo__casilla" aria-invalid={Boolean(error)} aria-describedby={idError} {...resto} />
        <label htmlFor={id} className="campo__etiqueta-casilla">{children}</label>
      </div>
      {error && <p id={idError} className="campo__mensaje-error" role="alert">{error}</p>}
    </div>
  )
}
