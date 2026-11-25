import { useState } from 'react'
import Boton from './Boton'

/** Abre la impresión con la imagen de papel ya decodificada, también con la caché fría. */
export default function BotonImprimir({ referenciaPapel, children, className }) {
  const [preparando, setPreparando] = useState(false)
  const [error, setError] = useState(false)

  async function imprimir() {
    setPreparando(true)
    setError(false)
    const imagen = referenciaPapel.current
    try {
      await imagen.decode()
      if (referenciaPapel.current === imagen) window.print()
    } catch {
      setError(true)
    } finally {
      setPreparando(false)
    }
  }

  return <>
    <Boton variante="secundario" className={className} cargando={preparando} onClick={imprimir}>{children}</Boton>
    {error && <span role="alert" className="no-imprimir">No se pudo preparar la impresión. Recarga la página y vuelve a intentarlo.</span>}
  </>
}
