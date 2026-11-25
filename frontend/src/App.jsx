import { NavegacionProvider } from './context/NavegacionContext'
import { PreferenciasProvider } from './context/PreferenciasContext'
import { SesionProvider } from './context/SesionContext'
import AppRouter from './routes/AppRouter'

export default function App() {
  return (
    <PreferenciasProvider>
      <SesionProvider>
        <NavegacionProvider>
          <AppRouter />
        </NavegacionProvider>
      </SesionProvider>
    </PreferenciasProvider>
  )
}
